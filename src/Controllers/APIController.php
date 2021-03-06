<?php

namespace MadeHQ\Gutenberg\Controllers;

use SilverStripe\Control\{Controller, HTTPRequest, HTTPResponse, HTTP};
use SilverStripe\ORM\DataObject;
use SilverStripe\Core\Convert;
use SilverStripe\Assets\File;

use SilverStripe\AssetAdmin\Controller\AssetAdmin;
use SilverStripe\AssetAdmin\Model\ThumbnailGenerator;
use SilverStripe\AssetAdmin\Forms\UploadField;

use Embed\Embed;
use Embed\Http\CurlDispatcher;

use MadeHQ\Cloudinary\Model\Image;

class APIController extends Controller
{
    private $thumbnailGenerator;

    /**
     * Thumbnail Width to be used for the image to appear in the editor
     * If set to 0 then it will be ignored
     * @var Int
     */
    private static $max_preview_width = 800;

    /**
     * @var array
     */
    private static $allowed_actions = [
        'oembed',
        'posts',
        'none',
        'filedata',
    ];

    /**
     * @config
     * @var array
     */
    private static $oembed_options = [
        'min_image_width' => 60,
        'min_image_height' => 60,
        'html' => [
            'max_images' => 10,
            'external_images' => false,
        ],
    ];

    /**
     * Used to get File data to be used in the File Selector
     * @param HTTPRequest $request
     * @return HTTPResponse
     */
    public function filedata(HTTPRequest $request)
    {
        HTTP::set_cache_age(60);

        if (!$request->param('ID')) {
            return $this->output();
        }
        if (!($file = File::get_by_id(File::class, $request->param('ID')))) {
            return $this->output();
        }

        $originalWidth = $file->getWidth();
        $originalHeight = $file->getHeight();

        if ($file instanceof Image && !$originalWidth && !$originalHeight) {
            list($originalWidth, $originalHeight) = getimagesize($file->SecureURL);
        }

        list($previewWidth, $previewHeight) = static::calculateWidthHeight(
            $originalWidth,
            $originalHeight,
            static::config()->uninherited('max_preview_width')
        );

        $smallWidth = UploadField::config()->uninherited('thumbnail_width');
        $smallHeight = UploadField::config()->uninherited('thumbnail_height');

        $largeWidth = AssetAdmin::config()->uninherited('thumbnail_width');
        $largeHeight = AssetAdmin::config()->uninherited('thumbnail_height');

        return $this->output([
            'id' => $file->ID,
            'title' => $file->Title,
            'exists' => true,
            'type' => $file->Type,
            'category' => File::get_app_category($file->Format),
            'name' => $file->Name,
            'url' => $file->SecureURL,
            'largeThumbnail' => $this->getThumbnailGenerator()->generateThumbnailLink($file, $previewWidth, $previewHeight),
            'smallThumbnail' => $this->getThumbnailGenerator()->generateThumbnailLink($file, $smallWidth, $smallHeight),
            'thumbnail' => $this->getThumbnailGenerator()->generateThumbnailLink($file, $largeWidth, $largeHeight),
            'width' => $originalWidth,
            'height' => $originalHeight,
            'previewWidth' => $previewWidth,
            'previewHeight' => $previewHeight,
        ]);
    }

    private static function calculateWidthHeight(int $originalWidth = null, int $originalHeight = null, int $width)
    {
        if ($width > $originalWidth) {
            return [
                $originalWidth,
                $originalHeight
            ];
        }
        $widthRatio = $width / $originalWidth;
        $height = $originalHeight * $widthRatio;
        return [
            (int)$width,
            (int)$height
        ];
    }

    /**
     * @param HTTPRequest $request
     * @return HTTPResponse
     */
    public function oembed(HTTPRequest $request)
    {
        HTTP::set_cache_age(600);

        // Grab the URL
        $url = $request->getVar('url');

        if (is_null($url) || !strlen($url)) {
            return $this->output(null);
        }

        if (stripos($url, 'itunes.apple.com') !== false) {
            return $this->output(
                static::get_podcast_details($url)
            );
        }

        try {
            // Embed options
            $options = array_merge(
                Embed::$default_config, static::$oembed_options
            );

            // Useful if we ever wish to find out why fetch went wrong
            $dispatcher = new CurlDispatcher();

            // Try to fetch data
            $webpage = Embed::create($url, $options, $dispatcher);

            // Get all providers
            $providers = $webpage->getProviders();

            if (array_key_exists('oembed', $providers)) {
                $data = $providers['oembed']->getBag()->getAll();
            } else {
                $data = null;
            }
        } catch (\Exception $exception) {
            // Don't care about errors
            $data = null;
        }

        return $this->output($data);
    }

    /**
     * @param string $url
     * @return array|null
     */
    public static function get_podcast_details($url)
    {
        if (!preg_match('/id(\d+)/', $url, $matches)) {
            return null;
        }

        $feed = file_get_contents(
            'https://itunes.apple.com/lookup?entity=podcast&id=' . $matches[1]
        );

        $feed = json_decode(trim($feed));

        if (!$feed || (int) $feed->resultCount !== 1) {
            return null;
        }

        $result = $feed->results[0];

        $rss = file_get_contents((string) $result->feedUrl);

        $rss = simplexml_load_string($rss);

        $items = [];

        foreach ($rss->channel->item as $item) {
            array_push($items, [
                'title' => (string) $item->title,
                'mp3' => (string) $item->enclosure->attributes()->url,
            ]);
        }

        if (!count($items)) {
            return null;
        }

        return [
            'title' => trim((string) $rss->channel->title),
            'description' => (string) $rss->channel->description,
            'artwork' => (string) $result->artworkUrl600,
            'url' => (string) $result->collectionViewUrl,
            'items' => $items,
        ];
    }

    /**
     * @param HTTPRequest $request
     * @return HTTPResponse
     */
    public function posts(HTTPRequest $request)
    {
        // Grab the URL
        $search = $request->getVar('search');
        $perPage = $request->getVar('per_page');

        // Do search
        $pages = DataObject::get('Page')
            ->filter([
                'Title:PartialMatch' => Convert::raw2sql($search),
            ])
            ->limit($perPage)
            ->sort('LastEdited DESC');

        // Create a structrue that Gutenberg expects
        $data = [];

        foreach ($pages as $page) {
            array_push($data, [
                'id' => $page->ID,
                'title' => [
                    'rendered' => $page->Title,
                ],
                'link' => $page->Link(),
            ]);
        }

        return $this->output($data);
    }

    /**
     * @param HTTPRequest $request
     * @return HTTPResponse
     */
    public function none()
    {
        return $this->output([]);
    }

    /**
     * @return HTTPResponse
     */
    public function output(array $data = null)
    {
        // Body & status code
        $responseBody = Convert::array2json($data);
        $statusCode = is_null($data) ? 404 : 200;

        // Get a new response going
        $response = new HTTPResponse($responseBody, $statusCode);

        // Add some headers
        $response->addHeader('Content-Type', 'application/json; charset=utf-8');
        $response->addHeader('Access-Control-Allow-Methods', 'GET');
        $response->addHeader('Access-Control-Allow-Origin', '*');

        // Return
        return $response;
    }

    public function getThumbnailGenerator()
    {
        return $this->thumbnailGenerator;
    }

    public function setThumbnailGenerator(ThumbnailGenerator $generator)
    {
        $this->thumbnailGenerator = $generator;
        return $this;
    }
}
