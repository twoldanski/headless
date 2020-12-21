<?php

/*
 * This file is part of the "headless" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 *
 * (c) 2020
 */

declare(strict_types=1);

namespace FriendsOfTYPO3\Headless\DataProcessing\RootSiteProcessing;

use TYPO3\CMS\Core\Site\Entity\Site;

interface SiteSortingInterface
{
    /**
     * @param array<Site> $sites
     * @param array<int, array<mixed>> $pages
     * @param string $sortingField
     */
    public function __construct(array $sites, array $pages, string $sortingField);

    /**
     * @return array<Site>
     */
    public function sort(): array;
}
