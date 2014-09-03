<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Functions for operating with the OneNote API
 *
 * @package    repository_onenote
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir.'/oauthlib.php');

/**
 * A helper class to access microsoft live resources using the api.
 *
 * This uses the microsoft API defined in
 * http://msdn.microsoft.com/en-us/library/hh243648.aspx
 *
 * @package    repository_onenote
 */
class microsoft_onenote extends oauth2_client {
    /** @var string OAuth 2.0 scope */
    const SCOPE = 'office.onenote_create';
    /** @var string Base url to access API */
    const API = 'https://www.onenote.com/api/v1.0';
    /** @var cache_session cache of foldernames */
    var $foldernamecache = null;

    var $notebook_id = null;
    var $notebook_name = "Moodle Notebook";
    
    /**
     * Construct a onenote request object
     *
     * @param string $clientid client id for OAuth 2.0 provided by microsoft
     * @param string $clientsecret secret for OAuth 2.0 provided by microsoft
     * @param moodle_url $returnurl url to return to after succseful auth
     */
    public function __construct($clientid, $clientsecret, $returnurl) {
        parent::__construct($clientid, $clientsecret, $returnurl, self::SCOPE);
        // Make a session cache
        $this->foldernamecache = cache::make('repository_onenote', 'foldername');
    }

    /**
     * Should HTTP GET be used instead of POST?
     *
     * The Microsoft API does not support POST, so we should use
     * GET instead (with the auth_token passed as a GET param).
     *
     * @return bool true if GET should be used
     */
    protected function use_http_get() {
        return true;
    }

    /**
     * Returns the auth url for OAuth 2.0 request
     * @return string the auth url
     */
    protected function auth_url() {
        return 'https://login.live.com/oauth20_authorize.srf';
    }

    /**
     * Returns the token url for OAuth 2.0 request
     * @return string the auth url
     */
    protected function token_url() {
        return 'https://login.live.com/oauth20_token.srf';
    }

    /**
     * Downloads a file to a  file from onenote using authenticated request
     *
     * @param string $id id of file
     * @param string $path path to save file to
     * @return array stucture for repository download_file
     */
    public function download_file($id, $path) {
        $url = self::API."/notebooks/".self::notebook_id."/sections";

        // Microsoft live redirects to the real download location..
        $this->setopt(array('CURLOPT_FOLLOWLOCATION' => true, 'CURLOPT_MAXREDIRS' => 3));
        $content = $this->get($url);
        file_put_contents($path, $content);
        return array('path'=>$path, 'url'=>$url);
    }

    /**
     * Returns a folder name property for a given folderid.
     *
     * @param string $folderid the folder id which is passed
     * @return mixed folder name or false in case of error
     */
    public function get_folder_name($folderid) {
        if (empty($folderid)) {
            throw new coding_exception('Empty folderid passed to get_folder_name');
        }

        // Cache based on oauthtoken and folderid.
        $cachekey = $this->folder_cache_key($folderid);

        if ($foldername = $this->foldernamecache->get($cachekey)) {
            return $foldername;
        }

        $url = self::API."/notebooks/{$folderid}";
        $ret = json_decode($this->get($url));
        if (isset($ret->error)) {
            $this->log_out();
            return false;
        }
        
        error_log(print_r($ret, true));

        $this->foldernamecache->set($cachekey, $ret->name);
        return $ret->name;
    }

    /**
     * Returns a list of files the user has formated for files api
     *
     * @param string $path the path which we are in
     * @return mixed Array of files formated for fileapoi
     */
    public function get_file_list($path = '') {
        global $OUTPUT;

        $precedingpath = '';
        if (empty($path)) {
            $url = self::API."/notebooks/".self::notebook_id."/sections";
        } else {
            $parts = explode('/', $path);
            $currentnotebook = array_pop($parts);
            $url = self::API."/{$currentnotebook}/sections/";
        }

        $ret = json_decode($this->get($url));

        if (isset($ret->error)) {
            $this->log_out();
            return false;
        }

        error_log(print_r($ret, true));
        
        $files = array();

        foreach ($ret->data as $file) {
            // switch($file->type) {
                // case 'folder':
                // case 'album':
                    // // Cache the foldername for future requests.
                    // $cachekey = $this->folder_cache_key($file->id);
                    // $this->foldernamecache->set($cachekey, $file->name);

                    // $files[] = array(
                        // 'title' => $file->name,
                        // 'path' => $path.'/'.$file->id,
                        // 'size' => 0,
                        // 'date' => strtotime($file->updated_time),
                        // 'thumbnail' => $OUTPUT->pix_url(file_folder_icon(90))->out(false),
                        // 'children' => array(),
                    // );
                    // break;
                // case 'photo':
                    // $files[] = array(
                        // 'title' => $file->name,
                        // 'size' => $file->size,
                        // 'date' => strtotime($file->updated_time),
                        // 'thumbnail' => $OUTPUT->pix_url(file_extension_icon($file->name, 90))->out(false),
                        // 'realthumbnail' => $file->picture,
                        // 'source' => $file->id,
                        // 'url' => $file->link,
                        // 'image_height' => $file->height,
                        // 'image_width' => $file->width,
                        // 'author' => $file->from->name,
                    // );
                    // break;
                // case 'video':
                    // $files[] = array(
                        // 'title' => $file->name,
                        // 'size' => $file->size,
                        // 'date' => strtotime($file->updated_time),
                        // 'thumbnail' => $OUTPUT->pix_url(file_extension_icon($file->name, 90))->out(false),
                        // 'realthumbnail' => $file->picture,
                        // 'source' => $file->id,
                        // 'url' => $file->link,
                        // 'author' => $file->from->name,
                    // );
                    // break;
                // case 'audio':
                    // $files[] = array(
                        // 'title' => $file->name,
                        // 'size' => $file->size,
                        // 'date' => strtotime($file->updated_time),
                        // 'thumbnail' => $OUTPUT->pix_url(file_extension_icon($file->name, 90))->out(false),
                        // 'source' => $file->id,
                        // 'url' => $file->link,
                        // 'author' => $file->from->name,
                    // );
                    // break;
                // case 'file':
                    // $files[] = array(
                        // 'title' => $file->name,
                        // 'size' => $file->size,
                        // 'date' => strtotime($file->updated_time),
                        // 'thumbnail' => $OUTPUT->pix_url(file_extension_icon($file->name, 90))->out(false),
                        // 'source' => $file->id,
                        // 'url' => $file->link,
                        // 'author' => $file->from->name,
                    // );
                    // break;
            // }
        }

        return $files;
    }

    /**
     * Returns a key for foldernane cache
     *
     * @param string $folderid the folder id which is to be cached
     * @return string the cache key to use
     */
    private function folder_cache_key($folderid) {
        // Cache based on oauthtoken and folderid.
        return $this->get_tokenname().'_'.$folderid;
    }
}
