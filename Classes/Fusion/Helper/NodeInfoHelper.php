<?php
namespace Neos\Neos\Ui\Fusion\Helper;

/*
 * This file is part of the Neos.Neos.Ui package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\NodeAccess\NodeAccessor\NodeAccessorInterface;
use Neos\ContentRepository\Projection\NodeHiddenState\NodeHiddenStateProjection;
use Neos\ContentRepository\SharedModel\NodeType\NodeTypeConstraintParser;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Eel\ProtectedContextAwareInterface;
use Neos\ContentRepository\NodeAccess\NodeAccessorManager;
use Neos\ContentRepository\SharedModel\NodeAddress;
use Neos\ContentRepository\SharedModel\NodeAddressFactory;
use Neos\ContentRepository\Projection\ContentGraph\NodeInterface;
use Neos\ContentRepository\Projection\ContentGraph\Nodes;
use Neos\Neos\FrontendRouting\NodeUriBuilder;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\TypeConverter\EntityToIdentityConverter;
use Neos\Neos\Ui\Domain\Service\NodePropertyConverterService;
use Neos\Neos\Ui\Domain\Service\UserLocaleService;
use Neos\Neos\Ui\Service\NodePolicyService;

/**
 * @Flow\Scope("singleton")
 */
class NodeInfoHelper implements ProtectedContextAwareInterface
{
    /**
     * @Flow\Inject
     * @var NodePolicyService
     */
    protected $nodePolicyService;

    /**
     * @Flow\Inject
     * @var UserLocaleService
     */
    protected $userLocaleService;

    /**
     * @Flow\Inject
     * @var ContentRepositoryRegistry
     */
    protected $contentRepositoryRegistry;

    /**
     * @Flow\Inject
     * @var NodeAccessorManager
     */
    protected $nodeAccessorManager;

    /**
     * @Flow\Inject
     * @var EntityToIdentityConverter
     */
    protected $entityToIdentityConverter;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var NodePropertyConverterService
     */
    protected $nodePropertyConverterService;

    /**
     * @Flow\InjectConfiguration(path="userInterface.navigateComponent.nodeTree.presets.default.baseNodeType", package="Neos.Neos")
     * @var string
     */
    protected $baseNodeType;

    /**
     * @Flow\InjectConfiguration(path="userInterface.navigateComponent.nodeTree.loadingDepth", package="Neos.Neos")
     * @var string
     */
    protected $loadingDepth;

    /**
     * @Flow\InjectConfiguration(path="nodeTypeRoles.document", package="Neos.Neos.Ui")
     * @var string
     */
    protected $documentNodeTypeRole;

    /**
     * @Flow\InjectConfiguration(path="nodeTypeRoles.ignored", package="Neos.Neos.Ui")
     * @var string
     */
    protected $ignoredNodeTypeRole;

    /**
     * @return ?array<string,mixed>
     * @deprecated See methods with specific names for different behaviors
     */
    public function renderNode(
        NodeInterface $node,
        ControllerContext $controllerContext = null,
        bool $omitMostPropertiesForTreeState = false,
        string $nodeTypeFilterOverride = null
    ):?array {
        return ($omitMostPropertiesForTreeState
            ? $this->renderNodeWithMinimalPropertiesAndChildrenInformation(
                $node,
                $controllerContext,
                $nodeTypeFilterOverride
            )
            : $this->renderNodeWithPropertiesAndChildrenInformation(
                $node,
                $controllerContext,
                $nodeTypeFilterOverride
            )
        );
    }

    /**
     * @return ?array<string,mixed>
     */
    public function renderNodeWithMinimalPropertiesAndChildrenInformation(
        NodeInterface $node,
        ControllerContext $controllerContext = null,
        string $nodeTypeFilterOverride = null
    ): ?array {
        $contentRepository = $this->contentRepositoryRegistry->get($node->getSubgraphIdentity()->contentRepositoryIdentifier);
        $nodeHiddenStateFinder = $contentRepository->projectionState(NodeHiddenStateProjection::class);

        /** @todo implement custom node policy service
        if (!$this->nodePolicyService->isNodeTreePrivilegeGranted($node)) {
        return null;
        }*/
        $this->userLocaleService->switchToUILocale();

        $nodeInfo = $this->getBasicNodeInformation($node);
        $nodeInfo['properties'] = [
            // if we are only rendering the tree state,
            // ensure _isHidden is sent to hidden nodes are correctly shown in the tree.
            '_hidden' => $nodeHiddenStateFinder->findHiddenState(
                $node->getSubgraphIdentity()->contentStreamIdentifier,
                $node->getSubgraphIdentity()->dimensionSpacePoint,
                $node->getNodeAggregateIdentifier()
            )->isHidden(),
            '_hiddenInIndex' => $node->getProperty('_hiddenInIndex'),
            //'_hiddenBeforeDateTime' => $node->getHiddenBeforeDateTime() instanceof \DateTimeInterface,
            //'_hiddenAfterDateTime' => $node->getHiddenAfterDateTime() instanceof \DateTimeInterface,
        ];

        if ($controllerContext !== null) {
            $nodeInfo = array_merge($nodeInfo, $this->getUriInformation($node, $controllerContext));
        }

        $baseNodeType = $nodeTypeFilterOverride ?: $this->baseNodeType;
        $nodeTypeFilter = $this->buildNodeTypeFilterString(
            $this->nodeTypeStringsToList($baseNodeType),
            $this->nodeTypeStringsToList($this->ignoredNodeTypeRole)
        );

        $nodeInfo['children'] = $this->renderChildrenInformation($node, $nodeTypeFilter);

        $this->userLocaleService->switchToUILocale(true);

        return $nodeInfo;
    }

    /**
     * @return ?array<string,mixed>
     */
    public function renderNodeWithPropertiesAndChildrenInformation(
        NodeInterface $node,
        ControllerContext $controllerContext = null,
        string $nodeTypeFilterOverride = null
    ): ?array {
        /** @todo implement custom node policy service
        if (!$this->nodePolicyService->isNodeTreePrivilegeGranted($node)) {
        return null;
        }**/

        $this->userLocaleService->switchToUILocale();

        $nodeInfo = $this->getBasicNodeInformation($node);
        $nodeInfo['properties'] = $this->nodePropertyConverterService->getPropertiesArray($node);
        $nodeInfo['isFullyLoaded'] = true;

        if ($controllerContext !== null) {
            $nodeInfo = array_merge($nodeInfo, $this->getUriInformation($node, $controllerContext));
        }

        $baseNodeType = $nodeTypeFilterOverride ? $nodeTypeFilterOverride : $this->baseNodeType;
        $nodeInfo['children'] = $this->renderChildrenInformation($node, $baseNodeType);

        $this->userLocaleService->switchToUILocale(true);

        return $nodeInfo;
    }

    /**
     * Get the "uri" and "previewUri" for the given node
     *
     * @param NodeInterface $node
     * @param ControllerContext $controllerContext
     * @return array<string,string>
     */
    protected function getUriInformation(NodeInterface $node, ControllerContext $controllerContext): array
    {
        $nodeInfo = [];
        if (!$node->getNodeType()->isOfType($this->documentNodeTypeRole)) {
            return $nodeInfo;
        }
        $nodeInfo['uri'] = $this->previewUri($node, $controllerContext);
        return $nodeInfo;
    }

    /**
     * Get the basic information about a node.
     *
     * @return array<string,mixed>
     */
    protected function getBasicNodeInformation(NodeInterface $node): array
    {
        $nodeAccessor = $this->nodeAccessorManager->accessorFor(
            $node->getSubgraphIdentity()
        );
        $parentNode = $nodeAccessor->findParentNode($node);

        $contentRepository = $this->contentRepositoryRegistry->get($node->getSubgraphIdentity()->contentRepositoryIdentifier);
        $nodeAddressFactory = NodeAddressFactory::create($contentRepository);

        return [
            'contextPath' => $nodeAddressFactory->createFromNode($node)->serializeForUri(),
            'name' => $node->getNodeName() ? $node->getNodeName()->jsonSerialize() : null,
            'identifier' => $node->getNodeAggregateIdentifier()->jsonSerialize(),
            'nodeType' => $node->getNodeType()->getName(),
            'label' => $node->getLabel(),
            'isAutoCreated' => self::isAutoCreated($node, $nodeAccessor),
            // TODO: depth is expensive to calculate; maybe let's get rid of this?
            'depth' => $nodeAccessor->findNodePath($node)->getDepth(),
            'children' => [],
            'parent' => $parentNode ? $nodeAddressFactory->createFromNode($parentNode)->serializeForUri() : null,
            'matchesCurrentDimensions' => $node->getSubgraphIdentity()->dimensionSpacePoint->equals($node->getOriginDimensionSpacePoint())
        ];
    }

    public static function isAutoCreated(NodeInterface $node, NodeAccessorInterface $nodeAccessor): bool
    {
        $parent = $nodeAccessor->findParentNode($node);
        if ($parent) {
            if (array_key_exists((string)$node->getNodeName(), $parent->getNodeType()->getAutoCreatedChildNodes())) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get information for all children of the given parent node.
     *
     * @param NodeInterface $node
     * @param string $nodeTypeFilterString
     * @return array<int,array<string,string>>
     * @throws \Neos\ContentRepository\SharedModel\NodeAddressCannotBeSerializedException
     */
    protected function renderChildrenInformation(NodeInterface $node, string $nodeTypeFilterString): array
    {
        $contentRepository = $this->contentRepositoryRegistry->get($node->getSubgraphIdentity()->contentRepositoryIdentifier);
        $nodeTypeConstraintParser = NodeTypeConstraintParser::create($contentRepository->getNodeTypeManager());
        $nodeAccessor = $this->nodeAccessorManager->accessorFor(
            $node->getSubgraphIdentity()
        );

        $documentChildNodes = $nodeAccessor->findChildNodes(
            $node,
            $nodeTypeConstraintParser->parseFilterString($nodeTypeFilterString)
        );
        // child nodes for content tree, must not include those nodes filtered out by `baseNodeType`
        $contentChildNodes = $nodeAccessor->findChildNodes(
            $node,
            $nodeTypeConstraintParser->parseFilterString(
                $this->buildContentChildNodeFilterString()
            )
        );
        $childNodes = $documentChildNodes->merge($contentChildNodes);

        $infos = [];
        foreach ($childNodes as $childNode) {
            $contentRepository = $this->contentRepositoryRegistry->get($childNode->getSubgraphIdentity()->contentRepositoryIdentifier);
            $nodeAddressFactory = NodeAddressFactory::create($contentRepository);
            $infos[] = [
                'contextPath' => $nodeAddressFactory->createFromNode($childNode)->serializeForUri(),
                'nodeType' => $childNode->getNodeType()->getName() // TODO: DUPLICATED; should NOT be needed!!!
            ];
        };
        return $infos;
    }

    /**
     * @param array<int,NodeInterface> $nodes
     * @return array<int,?array<string,mixed>>
     */
    public function renderNodes(
        array $nodes,
        ControllerContext $controllerContext,
        bool $omitMostPropertiesForTreeState = false
    ): array {
        $methodName = $omitMostPropertiesForTreeState
            ? 'renderNodeWithMinimalPropertiesAndChildrenInformation'
            : 'renderNodeWithPropertiesAndChildrenInformation';
        $mapper = function (NodeInterface $node) use ($controllerContext, $methodName) {
            return $this->$methodName($node, $controllerContext);
        };

        return array_values(array_filter(array_map($mapper, $nodes)));
    }

    /**
     * @param array<int,?array<string,mixed>> $nodes
     * @return array<int,?array<string,mixed>>
     */
    public function renderNodesWithParents(array $nodes, ControllerContext $controllerContext): array
    {
        // For search operation we want to include all nodes, not respecting the "baseNodeType" setting
        $baseNodeTypeOverride = $this->documentNodeTypeRole;
        $renderedNodes = [];

        /** @var NodeInterface $node */
        foreach ($nodes as $node) {
            $nodeAccessor = $this->nodeAccessorManager->accessorFor(
                $node->getSubgraphIdentity()
            );

            $nodePath = $nodeAccessor->findNodePath($node);
            if (array_key_exists($nodePath->jsonSerialize(), $renderedNodes)) {
                $renderedNodes[(string)$nodePath]['matched'] = true;
            } elseif ($renderedNode = $this->renderNodeWithMinimalPropertiesAndChildrenInformation(
                $node,
                $controllerContext,
                $baseNodeTypeOverride
            )) {
                $renderedNode['matched'] = true;
                $renderedNodes[(string)$nodePath] = $renderedNode;
            } else {
                continue;
            }

            $parentNode = $nodeAccessor->findParentNode($node);
            if ($parentNode === null) {
                // There are a multitude of reasons why a node might not have a parent
                // and we should ignore these gracefully.
                continue;
            }

            $parentNodePath = $nodeAccessor->findNodePath($parentNode);
            while ($parentNode->getNodeType()->isOfType($baseNodeTypeOverride)) {
                if (array_key_exists((string)$parentNodePath, $renderedNodes)) {
                    $renderedNodes[(string)$parentNodePath]['intermediate'] = true;
                } else {
                    $renderedParentNode = $this->renderNodeWithMinimalPropertiesAndChildrenInformation(
                        $parentNode,
                        $controllerContext,
                        $baseNodeTypeOverride
                    );
                    if ($renderedParentNode) {
                        $renderedParentNode['intermediate'] = true;
                        $renderedNodes[(string)$parentNodePath] = $renderedParentNode;
                    }
                }
                $parentNode = $nodeAccessor->findParentNode($parentNode);
                if ($parentNode === null) {
                    // There are a multitude of reasons why a node might not have a parent
                    // and we should ignore these gracefully.
                    break;
                }
            }
        }

        return array_values($renderedNodes);
    }

    /**
     * @param NodeInterface $documentNode
     * @param ControllerContext $controllerContext
     * @return array<string,mixed>>
     */
    public function renderDocumentNodeAndChildContent(
        NodeInterface $documentNode,
        ControllerContext $controllerContext
    ): array {
        return $this->renderNodeAndChildContent($documentNode, $controllerContext);
    }

    /**
     * @return array<string,mixed>>
     */
    protected function renderNodeAndChildContent(NodeInterface $node, ControllerContext $controllerContext): array
    {
        $reducer = function ($nodes, $node) use ($controllerContext) {
            return array_merge($nodes, $this->renderNodeAndChildContent($node, $controllerContext));
        };

        $contentRepository = $this->contentRepositoryRegistry->get($node->getSubgraphIdentity()->contentRepositoryIdentifier);
        $nodeAddressFactory = NodeAddressFactory::create($contentRepository);

        return array_reduce(
            $this->getChildNodes($node, $this->buildContentChildNodeFilterString())
                ->getIterator()->getArrayCopy(),
            $reducer,
            [
                $nodeAddressFactory->createFromNode($node)->serializeForUri()
                => $this->renderNodeWithPropertiesAndChildrenInformation($node, $controllerContext)
            ]
        );
    }

    /**
     * @return array<string,array<string,mixed>|null>
     */
    public function defaultNodesForBackend(
        NodeInterface $site,
        NodeInterface $documentNode,
        ControllerContext $controllerContext
    ): array {
        // does not support multiple CRs here yet
        $contentRepository = $this->contentRepositoryRegistry->get($site->getSubgraphIdentity()->contentRepositoryIdentifier);
        $nodeAddressFactory = NodeAddressFactory::create($contentRepository);

        return [
            ($nodeAddressFactory->createFromNode($site)->serializeForUri())
            => $this->renderNodeWithPropertiesAndChildrenInformation($site, $controllerContext),
            ($nodeAddressFactory->createFromNode($documentNode)->serializeForUri())
            => $this->renderNodeWithPropertiesAndChildrenInformation($documentNode, $controllerContext)
        ];
    }

    public function uri(NodeInterface|NodeAddress $nodeAddress, ControllerContext $controllerContext): string
    {
        if ($nodeAddress instanceof NodeInterface) {
            $contentRepository = $this->contentRepositoryRegistry->get($nodeAddress->getSubgraphIdentity()->contentRepositoryIdentifier);
            $nodeAddressFactory = NodeAddressFactory::create($contentRepository);
            $nodeAddress = $nodeAddressFactory->createFromNode($nodeAddress);
        }
        return (string)NodeUriBuilder::fromRequest($controllerContext->getRequest())->uriFor($nodeAddress);
    }

    public function previewUri(NodeInterface $node, ControllerContext $controllerContext): string
    {
        $contentRepository = $this->contentRepositoryRegistry->get($node->getSubgraphIdentity()->contentRepositoryIdentifier);
        $nodeAddressFactory = NodeAddressFactory::create($contentRepository);
        $nodeAddress = $nodeAddressFactory->createFromNode($node);
        return (string)NodeUriBuilder::fromRequest($controllerContext->getRequest())->previewUriFor($nodeAddress);
    }

    public function createRedirectToNode(NodeInterface $node, ControllerContext $controllerContext): string
    {
        $contentRepository = $this->contentRepositoryRegistry->get($node->getSubgraphIdentity()->contentRepositoryIdentifier);
        $nodeAddressFactory = NodeAddressFactory::create($contentRepository);
        $nodeAddress = $nodeAddressFactory->createFromNode($node);
        return $controllerContext->getUriBuilder()
            ->reset()
            ->setCreateAbsoluteUri(true)
            ->setFormat('html')
            ->uriFor('redirectTo', ['node' => $nodeAddress->serializeForUri()], 'Backend', 'Neos.Neos.Ui');
    }

    /**
     * @param string ...$nodeTypeStrings
     * @return string[]
     */
    protected function nodeTypeStringsToList(string ...$nodeTypeStrings)
    {
        $reducer = function ($nodeTypeList, $nodeTypeString) {
            $nodeTypeParts = explode(',', $nodeTypeString);
            foreach ($nodeTypeParts as $nodeTypeName) {
                $nodeTypeList[] = trim($nodeTypeName);
            }

            return $nodeTypeList;
        };

        return array_reduce($nodeTypeStrings, $reducer, []);
    }

    /**
     * @param array<int,string> $includedNodeTypes
     * @param array<int,string> $excludedNodeTypes
     */
    protected function buildNodeTypeFilterString(array $includedNodeTypes, array $excludedNodeTypes): string
    {
        $preparedExcludedNodeTypes = array_map(function ($nodeTypeName) {
            return '!' . $nodeTypeName;
        }, $excludedNodeTypes);
        $mergedIncludesAndExcludes = array_merge($includedNodeTypes, $preparedExcludedNodeTypes);
        return implode(',', $mergedIncludesAndExcludes);
    }

    protected function buildContentChildNodeFilterString(): string
    {
        return $this->buildNodeTypeFilterString(
            [],
            $this->nodeTypeStringsToList(
                $this->documentNodeTypeRole,
                $this->ignoredNodeTypeRole
            )
        );
    }

    private function getChildNodes(NodeInterface $node, string $nodeTypeFilterString): Nodes
    {
        $contentRepository = $this->contentRepositoryRegistry->get($node->getSubgraphIdentity()->contentRepositoryIdentifier);
        $nodeTypeConstraintParser = NodeTypeConstraintParser::create($contentRepository->getNodeTypeManager());

        return $this->nodeAccessorManager->accessorFor(
            $node->getSubgraphIdentity()
        )->findChildNodes(
            $node,
            $nodeTypeConstraintParser->parseFilterString($nodeTypeFilterString)
        );
    }

    public function nodeAddress(NodeInterface $node): NodeAddress
    {
        $contentRepository = $this->contentRepositoryRegistry->get($node->getSubgraphIdentity()->contentRepositoryIdentifier);
        $nodeAddressFactory = NodeAddressFactory::create($contentRepository);
        return $nodeAddressFactory->createFromNode($node);
    }

    public function serializedNodeAddress(NodeInterface $node): string
    {
        $contentRepository = $this->contentRepositoryRegistry->get($node->getSubgraphIdentity()->contentRepositoryIdentifier);
        $nodeAddressFactory = NodeAddressFactory::create($contentRepository);
        return $nodeAddressFactory->createFromNode($node)->serializeForUri();
    }

    public function inBackend(NodeInterface $node): bool
    {
        $contentRepository = $this->contentRepositoryRegistry->get($node->getSubgraphIdentity()->contentRepositoryIdentifier);
        $nodeAddressFactory = NodeAddressFactory::create($contentRepository);
        return !$nodeAddressFactory->createFromNode($node)->isInLiveWorkspace();
    }

    /**
     * @param string $methodName
     * @return boolean
     */
    public function allowsCallOfMethod($methodName)
    {
        return true;
    }
}
