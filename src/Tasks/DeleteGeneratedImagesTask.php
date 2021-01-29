<?php

namespace App\Tasks;

use SilverStripe\Dev\BuildTask;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Security\Permission;
use SilverStripe\Assets\Image;
use SilverStripe\ORM\DataObject;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use ReflectionMethod;
use SilverStripe\Assets\Storage\AssetStore;
use League\Flysystem\Filesystem;

/**
 * Class DeleteGeneratedImagesTask
 *
 * Hack to allow removing manipulated images
 * This is needed occasionally when manipulation functions change
 * It isn't directly possible with core so this is a workaround
 *
 * @see https://github.com/silverstripe/silverstripe-assets/issues/109
 * @package App\Tasks
 * @codeCoverageIgnore
 */
class DeleteGeneratedImagesTask extends BuildTask
{

    protected $title = 'Delete all generated images';

    protected $description = 'Deletes all resamples images from file system';

    public function run($request) // phpcs:ignore
    {
        $images = Image::get();

        if(!$images->exists()){
            echo('No images found in database');
            return;
        }

        foreach( $images as $image) {
            $asetValues = $image->File->getValue();
            $store = Injector::inst()->get(AssetStore::class);

            // warning - super hacky as accessing private methods
            $getID = new ReflectionMethod(FlysystemAssetStore::class, 'getFileID');
            $getID->setAccessible(true);
            if( !empty($asetValues['Hash']) ) {
                $flyID = $getID->invoke($store, $asetValues['Filename'], $asetValues['Hash']);
            }
            $getFileSystem = new ReflectionMethod(FlysystemAssetStore::class, 'getFilesystemFor');
            $getFileSystem->setAccessible(true);
            /** @var Filesystem $system */
            $system = $getFileSystem->invoke($store, $flyID);

            $findVariants = new ReflectionMethod(FlysystemAssetStore::class, 'findVariants');
            $findVariants->setAccessible(true);
            if( $flyID ) {
                foreach ($findVariants->invoke($store, $flyID, $system) as $variant) {
                    $isGenerated = strpos($variant, '__');
                    if (!$isGenerated) {
                        continue;
                    }
                    $system->delete($variant);
                }
            }
            echo "Deleted generated images for $image->Name" . "<br>";
        }

    }
}