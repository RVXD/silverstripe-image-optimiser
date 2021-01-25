<?php
namespace Axllent\ImageOptimiser\Flysystem;

use SilverStripe\Assets\Flysystem\FlysystemAssetStore as SS_FlysystemAssetStore;
use Intervention\Image\ImageManagerStatic as Image;
use SilverStripe\Assets\ImageManipulation;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use Spatie\ImageOptimizer\OptimizerChain;

/**
 * Optimised Flysystem AssetStore
 * ==============================
 *
 * Extends SilverStripe\Assets\Flysystem\FlysystemAssetStore
 * to automatically optimise files prior to storage.
 *
 * @license: MIT-style license http://opensource.org/licenses/MIT
 * @author:  Techno Joy development team (www.technojoy.co.nz)
 */
class FlysystemAssetStore extends SS_FlysystemAssetStore
{
    use Configurable;

    /**
     * Default Image Optimizer config
     *
     * @var array
     */
    private static $chains = [
        'Spatie\ImageOptimizer\Optimizers\Jpegoptim' => [
            '--max=85',
            '--all-progressive',
        ],
        'Spatie\ImageOptimizer\Optimizers\Pngquant'  => [
            '--force',
        ],
        'Spatie\ImageOptimizer\Optimizers\Optipng'   => [
            '-i0',
            '-o2',
            '-quiet',
        ],
        'Spatie\ImageOptimizer\Optimizers\Gifsicle'  => [
            '-b',
            '-O3',
        ],
    ];


    private static $webp_default_quality = 80;

    public function __construct()
    {
        $this->webp_quality = $this->config()->webp_default_quality;
    }

    /**
     * Asset Store file from local file
     *
     * @param String $path     Local path
     * @param String $filename Optional filename
     * @param String $hash     Optional hash
     * @param String $variant  Optional variant
     * @param Array  $config   Optional config options
     *
     * @return void
     */
    public function setFromLocalFile($path, $filename = null, $hash = null, $variant = null, $config = [])
    {
        $this->_optimisePath($path, $filename);
        $this->createWebPImage($path, $filename, $hash, $variant);

        return parent::setFromLocalFile($path, $filename, $hash, $variant, $config);
    }

    /**
     * Asset Store file from string
     *
     * @param String $data     File string
     * @param String $filename Optional file name
     * @param String $hash     Optional hash
     * @param String $variant  Optional variant
     * @param Array  $config   Optional config options
     *
     * @return void
     */
    public function setFromString($data, $filename, $hash = null, $variant = null, $config = [])
    {
        if ($filename) {
            $extension = substr(strrchr($filename, '.'), 1);
            $tmp_file  = TEMP_PATH . DIRECTORY_SEPARATOR . 'raw_' . uniqid() . '.' . $extension;
            file_put_contents($tmp_file, $data);
            $this->_optimisePath($tmp_file, $filename);
            $this->createWebPImage($tmp_file, $filename, $hash, $variant);

            $data = file_get_contents($tmp_file);
            unlink($tmp_file);
        }

        return parent::setFromString($data, $filename, $hash, $variant, $config);
    }

    /**
     * Optimise a file path
     * Silently ignores unsupported filetypes
     *
     * @param String $path     Path to file
     * @param String $filename File name
     *
     * @return void
     */
    private function _optimisePath($path, $filename = null)
    {
        if (!$filename) {
            // we do not know the name, so probably cannot
            // identfy what file it actually is, skip processing
            return;
        }

        $extension = strtolower(substr(strrchr($filename, '.'), 1));

        $tmp_file = TEMP_PATH . DIRECTORY_SEPARATOR . 'optim_' . uniqid() . '.' . $extension;

        copy($path, $tmp_file);

        $chains = $this->config()->get('chains');

        // create optimizer
        $optimizer = new OptimizerChain;
        foreach ($chains as $class => $options) {
            $optimizer->addOptimizer(
                new $class($options)
            );
        }

        $optimizer->optimize($tmp_file);

        $raw_size   = filesize($path);
        $optim_size = filesize($tmp_file);

        if ($raw_size > $optim_size && $optim_size > 0) {
            // print "$filename = $raw_size:$optim_size, ";
            $raw = file_get_contents($tmp_file);
            file_put_contents($path, $raw);
        }

        unlink($tmp_file);
    }

    public function createWebPImage($path, $filename, $hash, $variant = false)
    {

        if (!function_exists('imagewebp')) {
            // webp not supported
            return;
        }

        $orgpath = Director::baseFolder().$this->getAsURL($filename, $hash, $variant);

        $img = Image::make($path);
        $img->save($this->createWebPName($orgpath), $this->webp_quality, 'webp');

        $img->destroy();
        gc_collect_cycles();
    }

    public function createWebPName($filename)
    {
        $picname = pathinfo($filename, PATHINFO_FILENAME);
        $directory = pathinfo($filename, PATHINFO_DIRNAME);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        return $directory.'/'.$picname.'_'.$extension.'.webp';
    }



}
