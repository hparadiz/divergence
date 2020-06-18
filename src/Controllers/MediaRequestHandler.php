<?php
/**
 * This file is part of the Divergence package.
 *
 * (c) Henry Paradiz <henry.paradiz@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Divergence\Controllers;

use Exception;
use Divergence\Helpers\JSON;
use Divergence\Models\Media\Media;
use Divergence\Models\ActiveRecord;

class MediaRequestHandler extends RecordsRequestHandler
{
    // RecordRequestHandler configuration
    public static $recordClass = Media::class;
    public static $accountLevelRead = false;
    public static $accountLevelBrowse = 'Staff';
    public static $accountLevelWrite = 'Staff';
    public static $accountLevelAPI = false;
    public static $browseLimit = 100;
    public static $browseOrder = ['ID' => 'DESC'];

    // configurables
    public static $defaultPage = 'browse';
    public static $defaultThumbnailWidth = 100;
    public static $defaultThumbnailHeight = 100;
    public static $uploadFileFieldName = 'mediaFile';
    public static $responseMode = 'html';

    public static $searchConditions = [
        'Caption' => [
            'qualifiers' => ['any','caption']
            ,'points' => 2
            ,'sql' => 'Caption LIKE "%%%s%%"',
        ]
        ,'CaptionLike' => [
            'qualifiers' => ['caption-like']
            ,'points' => 2
            ,'sql' => 'Caption LIKE "%s"',
        ]
        ,'CaptionNot' => [
            'qualifiers' => ['caption-not']
            ,'points' => 2
            ,'sql' => 'Caption NOT LIKE "%%%s%%"',
        ]
        ,'CaptionNotLike' => [
            'qualifiers' => ['caption-not-like']
            ,'points' => 2
            ,'sql' => 'Caption NOT LIKE "%s"',
        ],
    ];

    public static function handleRequest()
    {
        // handle json response mode
        if (static::peekPath() == 'json') {
            static::$responseMode = static::shiftPath();
        }

        // handle action
        switch ($action = static::shiftPath()) {

#    		case 'media':
#			{
#				return static::handleMediaRequest();
#			}

            case 'upload':
            {
                return static::handleUploadRequest();
            }

            case 'open':
            {
                $mediaID = static::shiftPath();

                return static::handleMediaRequest($mediaID);
            }

            case 'download':
            {
                $mediaID = static::shiftPath();
                $filename = urldecode(static::shiftPath());

                return static::handleDownloadRequest($mediaID, $filename);
            }

            case 'info':
            {
                $mediaID = static::shiftPath();

                return static::handleInfoRequest($mediaID);
            }

            case 'caption':
            {
                $mediaID = static::shiftPath();

                return static::handleCaptionRequest($mediaID);
            }

            case 'delete':
            {
                return static::handleDeleteRequest();
            }

            case 'thumbnail':
            {
                return static::handleThumbnailRequest();
            }

            case false:
            case '':
            case 'browse':
            {
                if ($_SERVER['REQUEST_METHOD'] == 'POST') {
                    return static::handleUploadRequest();
                }

                return static::handleBrowseRequest();
            }

            default:
            {
                if (ctype_digit($action)) {
                    return static::handleMediaRequest($action);
                } else {
                    return parent::handleRecordsRequest($action);
                }
            }
        }
    }


    public static function handleUploadRequest($options = [], $authenticationRequired = true)
    {
        // require authentication
        if ($authenticationRequired) {
            static::checkUploadAccess();
        }

        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            // init options
            $options = array_merge([
                'fieldName' => static::$uploadFileFieldName,
            ], $options);


            // check upload
            if (empty($_FILES[$options['fieldName']])) {
                return static::throwUploadError('You did not select a file to upload');
            }

            // handle upload errors
            if ($_FILES[$options['fieldName']]['error'] != UPLOAD_ERR_OK) {
                switch ($_FILES[$options['fieldName']]['error']) {
                    case UPLOAD_ERR_NO_FILE:
                        return static::throwUploadError('You did not select a file to upload');

                    case UPLOAD_ERR_INI_SIZE:
                    case UPLOAD_ERR_FORM_SIZE:
                        return static::throwUploadError('Your file exceeds the maximum upload size. Please try again with a smaller file.');

                    case UPLOAD_ERR_PARTIAL:
                        return static::throwUploadError('Your file was only partially uploaded, please try again.');

                    default:
                        return static::throwUploadError('There was an unknown problem while processing your upload, please try again.');
                }
            }

            // init caption
            if (!isset($options['Caption'])) {
                if (!empty($_REQUEST['Caption'])) {
                    $options['Caption'] = $_REQUEST['Caption'];
                } else {
                    $options['Caption'] = preg_replace('/\.[^.]+$/', '', $_FILES[$options['fieldName']]['name']);
                }
            }

            // create media
            try {
                $Media = Media::createFromUpload($_FILES[$options['fieldName']]['tmp_name'], $options);
            } catch (Exception $e) {
                return static::throwUploadError('The file you uploaded is not of a supported media format');
            }
        } elseif ($_SERVER['REQUEST_METHOD'] == 'PUT') {
            $put = fopen("php://input", "r"); // open input stream

            $tmp = tempnam("/tmp", "emr");  // use PHP to make a temporary file
            $fp = fopen($tmp, "w"); // open write stream to temp file

            // write
            while ($data = fread($put, 1024)) {
                fwrite($fp, $data);
            }

            // close handles
            fclose($fp);
            fclose($put);

            // create media
            try {
                $Media = Media::createFromFile($tmp, $options);
            } catch (Exception $e) {
                return static::throwUploadError('The file you uploaded is not of a supported media format');
            }
        } else {
            return static::respond('upload');
        }

        // assign tag
        /*if (!empty($_REQUEST['Tag']) && ($Tag = Tag::getByHandle($_REQUEST['Tag']))) {
            $Tag->assignItem('Media', $Media->ID);
        }*/

        // assign context
        if (!empty($_REQUEST['ContextClass']) && !empty($_REQUEST['ContextID'])) {
            if (!is_subclass_of($_REQUEST['ContextClass'], ActiveRecord::class)
                || !in_array($_REQUEST['ContextClass']::getStaticRootClass(), Media::$fields['ContextClass']['values'])
                || !is_numeric($_REQUEST['ContextID'])) {
                return static::throwUploadError('Context is invalid');
            } elseif (!$Media->Context = $_REQUEST['ContextClass']::getByID($_REQUEST['ContextID'])) {
                return static::throwUploadError('Context class not found');
            }

            $Media->save();
        }

        return static::respond('uploadComplete', [
            'success' => (boolean)$Media
            ,'data' => $Media
            ,'TagID' => isset($Tag) ? $Tag->ID : null,
        ]);
    }


    public static function handleMediaRequest($mediaID)
    {
        if (empty($mediaID) || !is_numeric($mediaID)) {
            static::throwError('Missing or invalid media_id');
        }

        // get media
        try {
            $Media = Media::getById($mediaID);
        } catch (Exception $e) {
            return static::throwUnauthorizedError();
        }

        if (!$Media) {
            static::throwNotFoundError('Media ID #%u was not found', $media_id);
        }

        if (!static::checkReadAccess($Media)) {
            return static::throwNotFoundError();
        }

        if (static::$responseMode == 'json' || $_SERVER['HTTP_ACCEPT'] == 'application/json') {
            JSON::translateAndRespond([
                'success' => true
                ,'data' => $Media,
            ]);
        } else {

            // determine variant
            if ($variant = static::shiftPath()) {
                if (!$Media->isVariantAvailable($variant)) {
                    return static::throwNotFoundError();
                }
            } else {
                $variant = 'original';
            }

            // send caching headers
            $expires = 60*60*24*365;
            header("Cache-Control: public, max-age=$expires");
            header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time()+$expires));
            header('Pragma: public');

            // media are immutable for a given URL, so no need to actually check anything if the browser wants to revalidate its cache
            if (!empty($_SERVER['HTTP_IF_NONE_MATCH']) || !empty($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
                header('HTTP/1.0 304 Not Modified');
                exit();
            }

            // initialize response
            set_time_limit(0);
            $filePath = $Media->getFilesystemPath($variant);
            $fp = fopen($filePath, 'rb');
            $size = filesize($filePath);
            $length = $size;
            $start = 0;
            $end = $size - 1;

            header('Content-Type: '.$Media->getMIMEType($variant));
            header('ETag: media-'.$Media->ID.'-'.$variant);
            header('Accept-Ranges: bytes');

            // interpret range requests
            if (!empty($_SERVER['HTTP_RANGE'])) {
                $chunkStart = $start;
                $chunkEnd = $end;

                list(, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);

                if (strpos($range, ',') !== false) {
                    header('HTTP/1.1 416 Requested Range Not Satisfiable');
                    header("Content-Range: bytes $start-$end/$size");
                    exit();
                }

                if ($range == '-') {
                    $chunkStart = $size - substr($range, 1);
                } else {
                    $range = explode('-', $range);
                    $chunkStart = $range[0];
                    $chunkEnd = (isset($range[1]) && is_numeric($range[1])) ? $range[1] : $size;
                }

                $chunkEnd = ($chunkEnd > $end) ? $end : $chunkEnd;
                if ($chunkStart > $chunkEnd || $chunkStart > $size - 1 || $chunkEnd >= $size) {
                    header('HTTP/1.1 416 Requested Range Not Satisfiable');
                    header("Content-Range: bytes $start-$end/$size");
                    exit();
                }

                $start = $chunkStart;
                $end = $chunkEnd;
                $length = $end - $start + 1;

                fseek($fp, $start);
                header('HTTP/1.1 206 Partial Content');
            }

            // finish response
            header("Content-Range: bytes $start-$end/$size");
            header("Content-Length: $length");

            $buffer = 1024 * 8;
            while (!feof($fp) && ($p = ftell($fp)) <= $end) {
                if ($p + $buffer > $end) {
                    $buffer = $end - $p + 1;
                }

                echo fread($fp, $buffer);
                flush();
            }

            fclose($fp);
        }
    }

    public static function handleInfoRequest($mediaID)
    {
        if (empty($mediaID) || !is_numeric($mediaID)) {
            static::throwNotFoundError();
        }

        // get media
        try {
            $Media = Media::getById($mediaID);
        } catch (Exception $e) {
            return static::throwUnauthorizedError();
        }

        if (!$Media) {
            static::throwNotFoundError();
        }

        if (!static::checkReadAccess($Media)) {
            return static::throwUnauthorizedError();
        }

        return parent::handleRecordRequest($Media);
    }

    public static function handleDownloadRequest($media_id, $filename = false)
    {
        if (empty($media_id) || !is_numeric($media_id)) {
            static::throwNotFoundError();
        }

        // get media
        try {
            $Media = Media::getById($media_id);
        } catch (Exception $e) {
            return static::throwUnauthorizedError();
        }


        if (!$Media) {
            static::throwNotFoundError();
        }

        if (!static::checkReadAccess($Media)) {
            return static::throwUnauthorizedError();
        }

        // determine filename
        if (empty($filename)) {
            $filename = $Media->Caption ? $Media->Caption : sprintf('%s_%u', $Media->ContextClass, $Media->ContextID);
        }

        if (strpos($filename, '.') === false) {
            // add extension
            $filename .= '.'.$Media->Extension;
        }

        header('Content-Type: '.$Media->MIMEType);
        header('Content-Disposition: attachment; filename="'.str_replace('"', '', $filename).'"');
        header('Content-Length: '.filesize($Media->FilesystemPath));

        readfile($Media->FilesystemPath);
        exit();
    }

    public static function handleCaptionRequest($media_id)
    {
        // require authentication
        $GLOBALS['Session']->requireAccountLevel('Staff');

        if (empty($media_id) || !is_numeric($media_id)) {
            static::throwNotFoundError();
        }

        // get media
        try {
            $Media = Media::getById($media_id);
        } catch (Exception $e) {
            return static::throwUnauthorizedError();
        }


        if (!$Media) {
            static::throwNotFoundError();
        }

        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $Media->Caption = $_REQUEST['Caption'];
            $Media->save();

            return static::respond('mediaCaptioned', [
                'success' => true
                ,'data' => $Media,
            ]);
        }

        return static::respond('mediaCaption', [
            'data' => $Media,
        ]);
    }

    public static function handleDeleteRequest(ActiveRecord $Record)
    {
        // require authentication
        $GLOBALS['Session']->requireAccountLevel('Staff');

        if ($mediaID = static::peekPath()) {
            $mediaIDs = [$mediaID];
        } elseif (!empty($_REQUEST['mediaID'])) {
            $mediaIDs = [$_REQUEST['mediaID']];
        } elseif (is_array($_REQUEST['media'])) {
            $mediaIDs = $_REQUEST['media'];
        }

        $deleted = [];
        foreach ($mediaIDs as $mediaID) {
            if (!is_numeric($mediaID)) {
                continue;
            }

            // get media
            $Media = Media::getByID($mediaID);

            if (!$Media) {
                static::throwNotFoundError();
            }

            if ($Media->destroy()) {
                $deleted[] = $Media;
            }
        }

        return static::respond('mediaDeleted', [
            'success' => true
            ,'data' => $deleted,
        ]);
    }






    public static function handleThumbnailRequest(Media $Media = null)
    {
        // send caching headers
        $expires = 60*60*24*365;
        header("Cache-Control: public, max-age=$expires");
        header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time()+$expires));
        header('Pragma: public');


        // thumbnails are immutable for a given URL, so no need to actually check anything if the browser wants to revalidate its cache
        if (!empty($_SERVER['HTTP_IF_NONE_MATCH']) || !empty($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
            header('HTTP/1.0 304 Not Modified');
            exit();
        }


        // get media
        if (!$Media) {
            if (!$mediaID = static::shiftPath()) {
                return static::throwNotFoundError();
            } elseif (!$Media = Media::getByID($mediaID)) {
                return static::throwNotFoundError();
            }
        }


        // get format
        if (preg_match('/^(\d+)x(\d+)(x([0-9A-F]{6})?)?$/i', static::peekPath(), $matches)) {
            static::shiftPath();
            $maxWidth = $matches[1];
            $maxHeight = $matches[2];
            $fillColor = !empty($matches[4]) ? $matches[4] : false;
        } else {
            $maxWidth = static::$defaultThumbnailWidth;
            $maxHeight = static::$defaultThumbnailHeight;
            $fillColor = false;
        }

        if (static::peekPath() == 'cropped') {
            static::shiftPath();
            $cropped = true;
        } else {
            $cropped = false;
        }


        // fetch thumbnail
        try {
            // get thumbnail
            $thumbPath = $Media->getThumbnail($maxWidth, $maxHeight, $fillColor, $cropped);
        } catch (Exception $e) {
            /*\Emergence\Logger::general_warning('Caught exception while creating thumbnail for media, returning server error', array(
                'exceptionClass' => get_class($e)
                ,'exceptionMessage' => $e->getMessage()
                ,'exceptionCode' => $e->getCode()
                ,'recordData' => $Media->getData()
                ,'thumbFormat' => array(
                    'maxWidth' => $maxWidth
                    ,'maxHeight' => $maxHeight
                    ,'fillColor' => $fillColor
                    ,'cropped' => $cropped
                )
            ));*/

            return static::throwNotFoundError();
        }


        // dump it out
        header("ETag: media-$Media->ID-$maxWidth-$maxHeight-$fillColor-$cropped");
        header("Content-Type: $Media->ThumbnailMIMEType");
        header('Content-Length: '.filesize($thumbPath));
        readfile($thumbPath);
        exit();
    }



    public static function handleManageRequest()
    {
        // access control
        $GLOBALS['Session']->requireAccountLevel('Staff');

        return static::respond('manage');
    }



    public static function handleBrowseRequest($options = [], $conditions = [], $responseID = null, $responseData = [])
    {
        // apply tag filter
        if (!empty($_REQUEST['tag'])) {
            // get tag
            if (!$Tag = Tag::getByHandle($_REQUEST['tag'])) {
                return static::throwNotFoundError();
            }

            $conditions[] = 'ID IN (SELECT ContextID FROM tag_items WHERE TagID = '.$Tag->ID.' AND ContextClass = "Product")';
        }


        // apply context filter
        if (!empty($_REQUEST['ContextClass'])) {
            $conditions['ContextClass'] = $_REQUEST['ContextClass'];
        }

        if (!empty($_REQUEST['ContextID']) && is_numeric($_REQUEST['ContextID'])) {
            $conditions['ContextID'] = $_REQUEST['ContextID'];
        }

        return parent::handleBrowseRequest($options, $conditions, $responseID, $responseData);
    }



    #	public static function handleMediaRequest()
    #	{
    #		if(static::peekPath() == 'delete')
    #		{
    #			return static::handleMediaDeleteRequest();
    #		}
    #
    #
    #		// get media
    #		$media = JSON::translateRecords(Media::getAll(), true);
    #
    #		// get tag media assignments
    #		$media_tags = Tag::getAllItems('media');
    #
    #		// inject album assignments to photo records
    #		foreach($media_tags AS $media_id => $tags)
    #		{
    #			foreach($tags AS $tag)
    #			{
    #				$media[$media_id]['tags'][] = $tag['tag_id'];
    #			}
    #		}
    #
    #		return static::respond('media', array(
    #			'success' => true
    #			,'data' => array_values($media)
    #		));
    #	}


    public static function handleMediaDeleteRequest()
    {
        // sanity check
        if (empty($_REQUEST['media']) || !is_array($_REQUEST['media'])) {
            static::throwNotFoundError();
        }

        // retrieve photos
        $media_array = [];
        foreach ($_REQUEST['media'] as $media_id) {
            if (!is_numeric($media_id)) {
                static::throwNotFoundError();
            }

            if ($Media = Media::getById($media_id)) {
                $media_array[$Media->ID] = $Media;

                if (!static::checkWriteAccess($Media)) {
                    return static::throwUnauthorizedError();
                }
            }
        }

        // delete
        $deleted = [];
        foreach ($media_array as $media_id => $Media) {
            if ($Media->delete()) {
                $deleted[] = $media_id;
            }
        }

        return static::respond('mediaDeleted', [
            'success' => true
            ,'deleted' => $deleted,
        ]);
    }

    public static function throwUploadError($error)
    {
        return static::respond('error', [
            'success' => false,
            'failed' => [
                'errors'	=>	$error,
            ],
        ]);
    }
}
