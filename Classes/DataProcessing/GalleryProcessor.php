<?php

declare(strict_types=1);

/***
 *
 * This file is part of the "headless" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 *
 *  (c) 2020
 *
 ***/

namespace FriendsOfTYPO3\Headless\DataProcessing;

use FriendsOfTYPO3\Headless\Utility\FileUtility;
use TYPO3\CMS\Core\Imaging\ImageManipulation\CropVariantCollection;
use TYPO3\CMS\Core\Resource\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Service\ImageService;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

class GalleryProcessor extends \TYPO3\CMS\Frontend\DataProcessing\GalleryProcessor
{
    use DataProcessingTrait;

    /**
     * @var array<int, array<string, string|array>>
     */
    protected $fileObjects = [];

    /**
     * @inheritDoc
     */
    public function process(
        ContentObjectRenderer $cObj,
        array $contentObjectConfiguration,
        array $processorConfiguration,
        array $processedData
    ) {
        $processedData = parent::process(
            $cObj,
            $contentObjectConfiguration,
            $processorConfiguration,
            $processedData
        );

        return $this->removeDataIfnotAppendInConfiguration($processorConfiguration, $processedData);
    }

    /**
     * @inheritDoc
     *
     * replaced only calls to $this->getCroppedDimensionalPropertyFromProcessedFile()
     * because of already processed files by FilesProcessor
     */
    protected function calculateMediaWidthsAndHeights()
    {
        $columnSpacingTotal = ($this->galleryData['count']['columns'] - 1) * $this->columnSpacing;

        $galleryWidthMinusBorderAndSpacing = max($this->galleryData['width'] - $columnSpacingTotal, 1);

        if ($this->borderEnabled) {
            $borderPaddingTotal = ($this->galleryData['count']['columns'] * 2) * $this->borderPadding;
            $borderWidthTotal = ($this->galleryData['count']['columns'] * 2) * $this->borderWidth;
            $galleryWidthMinusBorderAndSpacing = $galleryWidthMinusBorderAndSpacing - $borderPaddingTotal - $borderWidthTotal;
        }

        // User entered a predefined height
        if ($this->equalMediaHeight) {
            $mediaScalingCorrection = 1;
            $maximumRowWidth = 0;

            // Calculate the scaling correction when the total of media elements is wider than the gallery width
            for ($row = 1; $row <= $this->galleryData['count']['rows']; $row++) {
                $totalRowWidth = 0;
                for ($column = 1; $column <= $this->galleryData['count']['columns']; $column++) {
                    $fileKey = (($row - 1) * $this->galleryData['count']['columns']) + $column - 1;
                    if ($fileKey > $this->galleryData['count']['files'] - 1) {
                        break 2;
                    }
                    $currentMediaScaling = $this->equalMediaHeight / max($this->getCroppedDimensionalPropertyFromProcessedFile($this->fileObjects[$fileKey], 'height'), 1);
                    $totalRowWidth += $this->getCroppedDimensionalPropertyFromProcessedFile($this->fileObjects[$fileKey], 'width') * $currentMediaScaling;
                }
                $maximumRowWidth = max($totalRowWidth, $maximumRowWidth);
                $mediaInRowScaling = $totalRowWidth / $galleryWidthMinusBorderAndSpacing;
                $mediaScalingCorrection = max($mediaInRowScaling, $mediaScalingCorrection);
            }

            // Set the corrected dimensions for each media element
            foreach ($this->fileObjects as $key => $fileObject) {
                $mediaHeight = floor($this->equalMediaHeight / $mediaScalingCorrection);
                $mediaWidth = floor(
                    $this->getCroppedDimensionalPropertyFromProcessedFile($fileObject, 'width') * ($mediaHeight / max($this->getCroppedDimensionalPropertyFromProcessedFile($fileObject, 'height'), 1))
                );
                $this->mediaDimensions[$key] = [
                    'width' => $mediaWidth,
                    'height' => $mediaHeight
                ];
            }

            // Recalculate gallery width
            $this->galleryData['width'] = floor($maximumRowWidth / $mediaScalingCorrection);

        // User entered a predefined width
        } elseif ($this->equalMediaWidth) {
            $mediaScalingCorrection = 1;

            // Calculate the scaling correction when the total of media elements is wider than the gallery width
            $totalRowWidth = $this->galleryData['count']['columns'] * $this->equalMediaWidth;
            $mediaInRowScaling = $totalRowWidth / $galleryWidthMinusBorderAndSpacing;
            $mediaScalingCorrection = max($mediaInRowScaling, $mediaScalingCorrection);

            // Set the corrected dimensions for each media element
            foreach ($this->fileObjects as $key => $fileObject) {
                $mediaWidth = floor($this->equalMediaWidth / $mediaScalingCorrection);
                $mediaHeight = floor(
                    $this->getCroppedDimensionalPropertyFromProcessedFile($fileObject, 'height') * ($mediaWidth / max($this->getCroppedDimensionalPropertyFromProcessedFile($fileObject, 'width'), 1))
                );
                $this->mediaDimensions[$key] = [
                    'width' => $mediaWidth,
                    'height' => $mediaHeight
                ];
            }

            // Recalculate gallery width
            $this->galleryData['width'] = floor($totalRowWidth / $mediaScalingCorrection);

        // Automatic setting of width and height
        } else {
            $maxMediaWidth = (int)($galleryWidthMinusBorderAndSpacing / $this->galleryData['count']['columns']);
            foreach ($this->fileObjects as $key => $fileObject) {
                $croppedWidth = $this->getCroppedDimensionalPropertyFromProcessedFile($fileObject, 'width');
                $mediaWidth = $croppedWidth > 0 ? min($maxMediaWidth, $croppedWidth) : $maxMediaWidth;
                $mediaHeight = floor(
                    $this->getCroppedDimensionalPropertyFromProcessedFile($fileObject, 'height') * ($mediaWidth / max($this->getCroppedDimensionalPropertyFromProcessedFile($fileObject, 'width'), 1))
                );
                $this->mediaDimensions[$key] = [
                    'width' => $mediaWidth,
                    'height' => $mediaHeight
                ];
            }
        }
    }

    /**
     * Replaces original method (because of already processed files)
     *
     * @param array $processedFile
     * @param string $property
     * @return int
     */
    private function getCroppedDimensionalPropertyFromProcessedFile(array $processedFile, string $property): int
    {
        if (empty($processedFile['properties']['crop'])) {
            return (int)$processedFile['properties']['dimensions'][$property];
        }

        $croppingConfiguration = $processedFile['properties']['crop'];
        $cropVariantCollection = CropVariantCollection::create((string)$croppingConfiguration);

        return (int)$cropVariantCollection->getCropArea($this->cropVariant)
            ->makeAbsoluteBasedOnFile($this->createFileObject($processedFile))
            ->asArray()[$property];
    }

    /**
     * Prepare the gallery data
     *
     * Make an array for rows, columns and configuration
     */
    protected function prepareGalleryData()
    {
        for ($row = 1; $row <= $this->galleryData['count']['rows']; $row++) {
            for ($column = 1; $column <= $this->galleryData['count']['columns']; $column++) {
                $fileKey = (($row - 1) * $this->galleryData['count']['columns']) + $column - 1;
                $fileObj = $this->fileObjects[$fileKey] ?? null;

                if ($fileObj) {
                    if (($this->equalMediaWidth || $this->equalMediaHeight) && $fileObj['properties']['type'] === 'image') {
                        $image = $this->getImageService()->getImage($fileObj['properties']['preProcessedUrl'], null, true);
                        $fileObj = $this->getFileUtility()->processFile($image, $this->mediaDimensions[$fileKey] ?? []);
                    }

                    $this->galleryData['rows'][$row]['columns'][$column] = $fileObj;
                }
            }
        }

        $this->galleryData['columnSpacing'] = $this->columnSpacing;
        $this->galleryData['border']['enabled'] = $this->borderEnabled;
        $this->galleryData['border']['width'] = $this->borderWidth;
        $this->galleryData['border']['padding'] = $this->borderPadding;
    }

    /**
     * @return FileUtility
     */
    protected function getFileUtility(): FileUtility
    {
        return GeneralUtility::makeInstance(FileUtility::class);
    }

    /**
     * @return ImageService
     */
    protected function getImageService(): ImageService
    {
        return GeneralUtility::makeInstance(ImageService::class);
    }

    /**
     * small helper for handling cropping based on already processed file
     *
     * @param array $processedFile
     * @return FileInterface
     */
    private function createFileObject(array $processedFile): FileInterface
    {
        return new class($processedFile['properties']) implements FileInterface {
            public function __construct(array $properties)
            {
                $this->properties = $properties['dimensions'];
            }

            public function hasProperty($key)
            {
                return array_key_exists($key, $this->properties);
            }

            public function getProperty($key)
            {
                if (!$this->hasProperty($key)) {
                    throw new \InvalidArgumentException('Property "' . $key . '" was not found in file reference or original file.', 1314226805);
                }
                return $this->properties[$key];
            }

            public function getSize()
            {
            }

            public function getSha1()
            {
            }

            public function getNameWithoutExtension()
            {
            }

            public function getExtension()
            {
            }

            public function getMimeType()
            {
            }

            public function getModificationTime()
            {
            }

            public function getCreationTime()
            {
            }

            public function getContents()
            {
            }

            public function setContents($contents)
            {
            }

            public function delete()
            {
            }

            public function rename($newName, $conflictMode = DuplicationBehavior::RENAME)
            {
            }

            public function getPublicUrl($relativeToCurrentScript = false)
            {
            }

            public function isIndexed()
            {
            }

            public function getForLocalProcessing($writable = true)
            {
            }

            public function toArray()
            {
            }

            public function getIdentifier()
            {
            }

            public function getName()
            {
            }

            public function getStorage()
            {
            }

            public function getHashedIdentifier()
            {
            }

            public function getParentFolder()
            {
            }
        };
    }
}
