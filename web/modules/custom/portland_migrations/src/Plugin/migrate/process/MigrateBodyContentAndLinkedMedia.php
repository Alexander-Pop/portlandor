<?php

namespace Drupal\portland_migrations\Plugin\migrate\process;

use Drupal\migrate\MigrateException;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;
use Drupal\media\Entity\Media;
use Drupal\file\Entity\File;

/**
 * The MigrateLinkedMedia plugin parses content and looks for images and links to documents.
 * If found, the source URL is used to download and store the file as a media entity, and the
 * link or image tag is converted to an entity-embed element.
 *
 * @MigrateProcessPlugin(
 *   id = "migrate_body_content_and_linked_media"
 * )
 */
class MigrateBodyContentAndLinkedMedia extends ProcessPluginBase {
  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {

    preg_match_all('/<a [^>]+>|<img [^>]+>/i', $value, $downloaded_file);
    if (!empty($downloaded_file[0])) {

      // migrated page title and POG URL, in case we need to report an error.
      $page_title = $row->getSourceProperty('CONTENT_NAME');
      $pog_url = $row->getSourceProperty('URL');

      $dom = Html::load($value);
      $xpath = new \DOMXPath($dom);

      $download_dir_uri = $this->generateDownloadDirectoryUri();

      // reusable db connection
      if (is_null($_SESSION['policies_dbConn'])) {
        $_SESSION['policies_dbConn'] = \Drupal::database();
      }

      // look for links with an href and save the linked file
      foreach ($xpath->query('//a[@href]|//img[@src]') as $link) {

        // parse url from link; it will be in an href or src attribute.
        $url = $link->getAttribute('href');
        if (is_null($url)) {
          $url = $link->getAttribute('src');
        }

        // troubleshooting
        if (preg_match("/.*360710.*/", $url)) {
          $halt = true;
        }

        // if url is empty or null, skip and continue
        if (is_null($url) || $url == "") {
          continue;
        }

        // if url is relative, prefix it with the POG domain; for downloading files,
        // we require fully qualified URLs. but we want to re-link to relative URLs.
        if (substr($url, 0, 1) == "/") {
          $url = "https://www.portlandoregon.gov" . $url;
        }

        // skip external links and leave the link tag alone
        $internal_link = $this->isInternalLink($url);
        if (!$internal_link) continue;

        // build filename/uri
        $arr_filename = $this->buildPogFilename($url);
        $filename = $arr_filename[0];
        $content_id = $arr_filename[1];

        // if buildPogFilename returns false, that means either the URL didn't have a
        // Content-Disposition header (not a binary file), the URL returned 404, or it was
        // a link to the homepage/root.
        if ($arr_filename === false) {
          continue;
        }
        $destination_uri = $download_dir_uri . "/" . $filename;

        $media_type = $this->getMediaType($filename);

        // Search for possible duplicates using filename sans POG content id
        $realpath_dir = drupal_realpath($download_dir_uri);
        $filename_search = str_replace($content_id, '*', $filename);
        $files = glob($realpath_dir . '/' . $filename_search);

        // if file exists with same content id, grab existing file and use it for link.
        // if file exists with different content id, treat it as new but add a note to the log.
        unset($downloaded_file);
      
        if ($files === false) {
          // return false means an error occurred searching for files. is it even worth the effort to handle it?
        }

        if (is_array($files) && count($files) > 0) {
          // a matching filename pattern was found...
          // if the content ids match, link to existing file.
          // if the ids don't match, treat it as new file and log possible duplicate.

          // ids match?
          if (strpos($files[0], $content_id) !== FALSE) {
            // use existing file. glob only returns path; we need to get fid and load file entity.
            $query = "SELECT fid FROM file_managed FM where uri = '" . $destination_uri . "'";
            $query = $_SESSION['policies_dbConn']->query($query);
            $result = $query->fetchAll();
            if (is_array($result) && count($result) > 1) {
              // duplicate document media entities exist! log it, but then use the first one found.
              $message = "Duplicate media entities found for $destination_uri. Using first one found, but consider removing duplicates.";
              \Drupal::logger('portland_migrations')->notice($message);
            }
            if (count($result) < 1) {
              // TODO: Need to handle this, create file?
              $message = "File exists in the file system but not in the database, SKIPPING. $files[0]";
              \Drupal::logger('portland_migrations')->notice($message);
              continue;
            }
            $fid = $result[0]->fid;
            $downloaded_file = \Drupal\file\Entity\File::load($fid);
        
          } else {
            // file may already exist, log it for review but use it.
            // all potential duplicates are downloaded and saved as individual files; they
            // must be manually cleaned up later, so this logging is important.
            $message = "Possible duplicate file from POG found--same filename with different content id.<br><br>File: $realpath_dir/$filename_search<br>Page: $page_title<br>POG URL: $pog_url";
            \Drupal::logger('portland_migrations')->warning($message);

            // download and save managed file if it's not a dupe
            try {
              $downloaded_file = system_retrieve_file($url, $destination_uri, TRUE);
            }
            catch (Exception $e) {
              $message = "Error occurred while trying to download URL target at " . $url . " and create managed file. Skipping file. Page: $page_title. Exception: " . $e->getMessage();
              \Drupal::logger('portland_migrations')->error($message);
              continue;
            }
          }
        } else {
          // download and save managed file if it's not a dupe
          try {
            $downloaded_file = system_retrieve_file($url, $destination_uri, TRUE);
          }
          catch (Exception $e) {
            $message = "Error occurred while trying to download URL target at " . $url . " and create managed file. Skipping file. Page: $page_title. Exception: " . $e->getMessage();
            \Drupal::logger('portland_migrations')->error($message);
            continue;
          }
        }

        if ($downloaded_file === FALSE || is_null($downloaded_file)) {
          $message = "Error retrieving file, possible 404 or temp dir can't be written to.<br><br>Page: $page_title<br>URL: $pog_url";
          \Drupal::logger('portland_migrations')->error($message);
          continue;
        }

        // modify link to use file URL
        $file_uri = $downloaded_file->getFileUri();
        $file_url = file_url_transform_relative(file_create_url($file_uri));
        $link->setAttribute("href", $file_url);

      }
      $output = Html::serialize($dom);
      $value = $output;
    }

    return $value;
  }

  protected function generateDownloadDirectoryUri() {
    // prepare download directory
    $folder_name = date("Y-m");
    $folder_uri = file_build_uri($folder_name);
    return $folder_uri;
  }

  protected function isInternalLink($url) {
    $url_host = parse_url($url, PHP_URL_HOST);
    // if host is anything but www.portlandoregon.gov or www.portlandonline.gov,
    // leave the link alone and continue. we don't want to download and embed
    // external link targets.
    if ($url_host != "www.portlandoregon.gov" && $url_host != "www.portlandonline.gov") {
      return false;
    } else {
      return true;
    }
  }

  protected function buildPogFilename($url) {
    // get content id from URL, might be like /bts/38249 or /image.cfm?id=38249 or /shared/cfm/slb.cfm?id=38249 or /auditor/29194?a=256111
    // this is appended to filename to make sure it's unique.
    $content_id = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME);
    // return immediately if no content_id; that means no filename/invalid URL
    if (!$content_id) return false;

    if (strtolower($content_id) == "image" || strtolower($content_id == "slb")) {
      $querystr = parse_url($url, PHP_URL_QUERY);
      $queries = array();
      parse_str($querystr, $queries);
      $content_id = $queries['id'];
    } else {
      $querystr = parse_url($url, PHP_URL_QUERY);
      $queries = array();
      parse_str($querystr, $queries);
      if (isset($queries['a'])) {
        $content_id = $queries['a'];
      }
    }

    // get name from Content-Disposition header; if not there, that means this isn't
    // a binary file URL, so we don't want it; return false.
    $headers = get_headers($url, 1);
    if (!isset($headers['Content-Disposition'])) {
      return false;
    }
    $content_disposition = $headers['Content-Disposition']; //"inline; filename="BCP_ENB_1.03EX.pdf""
    $parts = preg_split("/; /", $content_disposition);
    $filename_parts = preg_split("/=/", $parts[1]);
    $filename = preg_replace("/\"/", "", $filename_parts[1]);
    $basename = pathinfo($filename, PATHINFO_BASENAME);
    $extension = pathinfo($filename, PATHINFO_EXTENSION);
    $basename = preg_replace("/\.".$extension."/", "", $filename);

    if ($content_id == "image" || $content_id == "slb") {
      $final_filename = $basename . "." . $extension;
    } else {
      $final_filename = $basename . "-" . $content_id . "." . $extension;
    }

    // replace underscores with hypens
    $final_filename = preg_replace('/_/', '-', $final_filename);
    
    // transliterate filename to remove spaces, punctuation, illegal characters
    if (function_exists("transliterate_filenames_transliteration")) {
      $final_filename = transliterate_filenames_transliteration($final_filename);
    }

    return [$final_filename, $content_id];
  }

  protected function getMediaType($filename) {
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    switch ($ext) {
      case "jpg":
      case "jpeg":
      case "png":
      case "gif":
      case "bmp":
      case "tif":
      case "tiff":
        return "image";
        break;
      default:
        return "document";
        break;      
    }
  }

}