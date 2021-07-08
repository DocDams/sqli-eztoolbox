<?php

namespace SQLI\EzToolboxBundle\Services;

use Exception;
use eZ\Publish\API\Repository\ContentService;
use eZ\Publish\API\Repository\Exceptions\InvalidArgumentException;
use eZ\Publish\API\Repository\Exceptions\NotFoundException;
use eZ\Publish\API\Repository\Exceptions\UnauthorizedException;
use eZ\Publish\API\Repository\LocationService;
use eZ\Publish\API\Repository\SearchService;
use eZ\Publish\API\Repository\Values\Content\Content;
use eZ\Publish\API\Repository\Values\Content\Location;
use eZ\Publish\API\Repository\Values\Content\LocationQuery;
use eZ\Publish\API\Repository\Values\Content\Query;
use eZ\Publish\API\Repository\Values\Content\Query\Criterion;
use eZ\Publish\API\Repository\Values\Content\Query\SortClause;
use eZ\Publish\Core\Helper\FieldHelper;
use eZ\Publish\Core\Helper\TranslationHelper;
use eZ\Publish\Core\MVC\ConfigResolverInterface;

class FetchHelper
{
    public const LIMIT = 25;
    /** @var ConfigResolverInterface */
    private $configResolver;
    /** @var SearchService */
    private $searchService;
    /** @var LocationService */
    private $locationService;
    /** @var FieldHelper */
    private $fieldhelper;
    /** @var TranslationHelper */
    private $translationhelper;
    /** @var ContentService */
    private $contentservice;
    /** @var DataFormatterHelper */
    private $dataFormatterHelper;

    public function __construct(
        ConfigResolverInterface $configResolver,
        SearchService $searchService,
        LocationService $locationService,
        FieldHelper $fieldhelper,
        TranslationHelper $translationhelper,
        ContentService $contentservice,
        DataFormatterHelper $dataFormatterHelper
    ) {
        $this->configResolver = $configResolver;
        $this->searchService = $searchService;
        $this->locationService = $locationService;
        $this->fieldhelper = $fieldhelper;
        $this->translationhelper = $translationhelper;
        $this->contentservice = $contentservice;
        $this->dataFormatterHelper = $dataFormatterHelper;
    }

    /**
     * Similar as fetchChildren but returns Content for each child instead of Location
     *
     * @param Location|int $parentLocation
     * @param string|null $contentClass
     * @return Content[]
     * @throws InvalidArgumentException
     */
    public function fetchChildrenContent($parentLocation, $contentClass = null): array
    {
        $results = $this->fetchChildren($parentLocation, $contentClass);
        $items = [];

        /** @var Location $item */
        foreach ($results as $item) {
            $items[] = $item->getContent();
        }

        return $items;
    }

    /**
     * @param Location|int $parentLocation
     * @param string|null $contentClass
     * @param int $limit
     * @return Location[]
     * @throws InvalidArgumentException
     */
    public function fetchChildren($parentLocation, $contentClass = null, $limit = self::LIMIT): array
    {
        $params = [];

        if (!is_null($contentClass)) {
            $params[] = new Criterion\ContentTypeIdentifier($contentClass);
        }

        $parentLocationId = $parentLocation instanceof Location ? $parentLocation->id : $parentLocation;
        $params[] = new Criterion\ParentLocationId($parentLocationId);

        return $this->fetchLocationList($params, $limit);
    }

    /**
     * @param array $params
     * @param int $limit
     * @param int $offset
     * @param string $sortClauses
     *      parametre which specifies the way the request will be sorted : "depth" or "prioriy"
     * @return Location[]
     * @throws InvalidArgumentException
     */
    private function fetchLocationList(
        array $params,
        $sortClauses = "priority",
        $limit = self::LIMIT,
        $offset = 0
    ): array {
        $languages = $this->configResolver->getParameter('languages');
        $query = new LocationQuery();

        $query->query = new Criterion\LogicalAnd(
            array_merge(
                [
                    new Criterion\Visibility(Criterion\Visibility::VISIBLE),
                    new Criterion\LanguageCode($languages),
                ],
                $params
            )
        );
        $query->performCount = true;
        $query->limit = $limit;
        $query->offset = $offset;
        if ($sortClauses == "depth") {
            $query->sortClauses = [
                new SortClause\Location\Depth(Query::SORT_ASC),
            ];
        }
        if ($sortClauses == "priority") {
            $query->sortClauses = [
                new SortClause\Location\Priority(Query::SORT_ASC),
            ];
        }
        $results = $this->searchService->findLocations($query);
        $items = [];

        foreach ($results->searchHits as $item) {
            $items[] = $item->valueObject;
        }

        return $items;
    }

    /**
     * @param Location $parentLocation
     * @param null $contentClass
     * @param int $limit
     * @param int $offset
     * @param array $params
     * @return array
     * @throws InvalidArgumentException
     */
    public function fetchSubTree(
        Location $parentLocation,
        $contentClass = null,
        $limit = self::LIMIT,
        $offset = 0,
        array $params = []
    ): array {
        if (!is_null($contentClass)) {
            $params[] = new Criterion\ContentTypeIdentifier($contentClass);
        }

        $languages = $this->configResolver->getParameter('languages');
        $query = new LocationQuery();

        $query->query = new Criterion\LogicalAnd(
            array_merge(
                [
                    new Criterion\Visibility(Criterion\Visibility::VISIBLE),
                    new Criterion\LanguageCode($languages),
                    new Criterion\Subtree($parentLocation->pathString),
                ],
                $params
            )
        );

        if ($limit == -1) {
            $query->performCount = true;
            $query->limit = 0;
            $query->offset = 0;
            $results = $this->searchService->findLocations($query);

            $query->limit = $results->totalCount;
        } else {
            $query->limit = $limit;
            $query->offset = $offset;
        }

        $query->performCount = false;
        $query->sortClauses = [
            new SortClause\DateModified(Query::SORT_DESC),
        ];
        $results = $this->searchService->findLocations($query);

        $items = [];
        foreach ($results->searchHits as $item) {
            $items[] = $item->valueObject;
        }

        return $items;
    }

    /**
     * Fetch ancestor of $location with specified $contentType
     *
     * @param Location|int $location
     * @param string $contentType
     * @return Location|null
     * @throws InvalidArgumentException
     */
    public function fetchAncestors($location, string $contentType): ?array
    {
        if (!$location instanceof Location) {
            try {
                $location = $this->locationService->loadLocation($location);
            } catch (Exception $exception) {
                return null;
            }
        }

        $params =
            [
                new Criterion\ContentTypeIdentifier($contentType),
                new Criterion\Ancestor($location->pathString),
            ];

        $sortClauses = "depth";
        $items = $this->fetchLocationList($params, $sortClauses);
        return $items;
    }

    /**
     * Fetch ancestor of $location with specified $contentType
     *
     * @param Location|int $location
     * @param string $contentType
     * @param bool $highest
     *      param which specifies if fetchAncestor must return the most distant or closest ancestor
     * @return Location|null
     * @throws InvalidArgumentException
     */
    public function fetchAncestor($location, string $contentType, bool $highest = true): ?Location
    {
        $results = $this->fetchAncestors($location, $contentType);
        if ($highest) {
            $itemHit = array_shift($results);
        } elseif ($highest == false) {
            $itemHit = array_pop($results);
        }
        return ($itemHit instanceof Location) ? $itemHit : null;
    }

    /**
     * @param array $params
     * @return Location|null
     * @throws InvalidArgumentException
     */
    private function fetchLocation(array $params): ?Location
    {
        $results = $this->fetchLocationList($params, 1);

        $itemHit = reset($results);
        return ($itemHit instanceof Location) ? $itemHit : null;
    }

    /**
     * Fetch all contents which contains specified content into specified RelationList field
     *
     * @param Content|null $content
     * @param string $fieldIdentifier
     * @param null $contentClass
     * @return array
     * @throws InvalidArgumentException
     */
    public function fetchRelatedContents(?Content $content, string $fieldIdentifier, $contentClass = null): array
    {
        $params = [];

        $contentId = $content instanceof Content ? $content->id : $content;
        $params[] = new Criterion\FieldRelation($fieldIdentifier, Criterion\Operator::IN, [$contentId]);
        if (!is_null($contentClass)) {
            $params[] = new Criterion\ContentTypeIdentifier($contentClass);
        }

        return $this->fetchContentList($params);
    }

    /**
     * @param array $params
     * @param int $limit
     * @param int $offset
     * @return array
     * @throws InvalidArgumentException
     */
    private function fetchContentList(array $params, int $limit = self::LIMIT, int $offset = 0): array
    {
        $languages = $this->configResolver->getParameter('languages');
        $query = new Query();

        $query->query = new Criterion\LogicalAnd(
            array_merge(
                [
                    new Criterion\Visibility(Criterion\Visibility::VISIBLE),
                    new Criterion\LanguageCode($languages),
                ],
                $params
            )
        );
        $query->performCount = true;
        $query->limit = $limit;
        $query->offset = $offset;
        $results = $this->searchService->findContent($query);
        $items = [];

        foreach ($results->searchHits as $item) {
            $items[] = $item->valueObject;
        }

        return $items;
    }

    /**
     * Search a content in specified Location or the first of it's ancestors which have fieldIdentifier filled
     *
     * @param Location $location
     * @param string $fieldIdentifier
     * @return Location
     * @throws NotFoundException
     * @throws UnauthorizedException
     */
    public function recursiveAncestorGetField(Location $location, string $fieldIdentifier): ?Location
    {
        $content = $location->getContent();

        // Check if $location has a relation in field
        if (
            $content->getContentType()->getFieldDefinition($fieldIdentifier) == null ||
            $this->fieldhelper->isFieldEmpty($content, $fieldIdentifier)
        ) {
            // No relation to a header object then check parent location
            if ($location->parentLocationId == 2) {
                // If parent location is root node then no header is defined
                return null;
            } else {
                // Load parent location
                $parentLocation = $this->locationService->loadLocation($location->parentLocationId);

                return self::recursiveAncestorGetField($parentLocation, $fieldIdentifier);
            }
        } else {
            // Get field and returns it
            $fieldHeader = $this->translationhelper->getTranslatedField($content, $fieldIdentifier);
            $contentHeader = $this->contentservice->loadContent($fieldHeader->value->destinationContentId);

            return $this->locationService->loadLocation($contentHeader->contentInfo->mainLocationId);
        }
    }

    /**
     * @param int|Content $content
     * @param int|Location $locationSubtree
     * @return Location|null
     * @throws NotFoundException
     * @throws UnauthorizedException|InvalidArgumentException
     */
    public function fetchLocationFromContentInSubtree($content, $locationSubtree): ?Location
    {
        if ($content instanceof Content) {
            $contentId = $content->id;
        } else {
            $contentId = intval($content);
        }

        if (!$locationSubtree instanceof Location) {
            $locationSubtree = $this->locationService->loadLocation(intval($locationSubtree));
        }

        $params[] = new Criterion\Subtree($locationSubtree->pathString);
        $params[] = new Criterion\ContentId($contentId);

        return $this->fetchLocation($params);
    }

    public function getName(): string
    {
        return 'fetch_extension';
    }

    /**
     * @param array $params
     * @return Content|null
     * @throws InvalidArgumentException
     */
    private function fetchContent(array $params): ?Content
    {
        $results = $this->fetchContentList($params, 1);

        $itemHit = reset($results);

        return ($itemHit instanceof Content) ? $itemHit : null;
    }
}
