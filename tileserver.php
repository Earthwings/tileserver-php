<?php

/*
 * TileServer.php project
 * ======================
 * https://github.com/klokantech/tileserver-php/
 * Copyright (C) 2016 - Klokan Technologies GmbH
 */

global $config;
$config['serverTitle'] = 'Maps hosted with TileServer-php v2.0';
$config['availableFormats'] = array('png', 'jpg', 'jpeg', 'gif', 'webp', 'pbf', 'o5m', 'hybrid');
//$config['template'] = 'template.php';
//$config['baseUrls'] = array('t0.server.com', 't1.server.com');

Router::serve(array(
    '/:alpha/:number/:number/:alpha' => 'Wmts:getTile',
));

/**
 * Server base
 */
class Server {

  /**
   * Configuration of TileServer [baseUrls, serverTitle]
   * @var array
   */
  public $config;

  /**
   * Datasets stored in file structure
   * @var array
   */
  public $fileLayer = array();

  /**
   * Datasets stored in database
   * @var array
   */
  public $dbLayer = array();

  /**
   * PDO database connection
   * @var object
   */
  public $db;

  /**
   * Set config
   */
  public function __construct() {
    $this->config = $GLOBALS['config'];
    
    //Get config from enviroment
    $envServerTitle = getenv('serverTitle');
    if($envServerTitle !== FALSE){
      $this->config['serverTitle'] = $envServerTitle;
    }
    $envBaseUrls = getenv('baseUrls');
    if($envBaseUrls !== FALSE){
      $this->config['baseUrls'] = is_array($envBaseUrls) ? 
              $envBaseUrls : explode(',', $envBaseUrls);
    }
    $envTemplate = getenv('template');
    if($envBaseUrls !== FALSE){
      $this->config['template'] = $envTemplate;
    }
  }

  /**
   * Looks for datasets
   */
  public function setDatasets() {
    $mbts = glob('*.mbtiles');
    if ($mbts) {
      foreach (array_filter($mbts, 'is_readable') as $mbt) {
        $this->dbLayer[] = $this->metadataFromMbtiles($mbt);
      }
    }
  }

  /**
   * Processing params from router <server>/<layer>/<z>/<x>/<y>.ext
   * @param array $params
   */
  public function setParams($params) {
    if (isset($params[1])) {
      $this->layer = $params[1];
    }
    $params = array_reverse($params);
    if (isset($params[2])) {
      $this->z = $params[2];
      $this->x = $params[1];
      $file = explode('.', $params[0]);
      $this->y = $file[0];
      $this->ext = isset($file[1]) ? $file[1] : NULL;
    }
  }

  /**
   * Get variable don't independent on sensitivity
   * @param string $key
   * @return boolean
   */
  public function getGlobal($isKey) {
    $get = $_GET;
    foreach ($get as $key => $value) {
      if (strtolower($isKey) == strtolower($key)) {
        return $value;
      }
    }
    return FALSE;
  }

  /**
   * Testing if is a database layer
   * @param string $layer
   * @return boolean
   */
  public function isDBLayer($layer) {
    if (is_file($layer . '.mbtiles')) {
      return TRUE;
    } else {
      return FALSE;
    }
  }

  /**
   * Testing if is a file layer
   * @param string $layer
   * @return boolean
   */
  public function isFileLayer($layer) {
    if (is_dir($layer)) {
      return TRUE;
    } else {
      return FALSE;
    }
  }

  /**
   * Loads metadata from MBtiles
   * @param string $mbt
   * @return object
   */
  public function metadataFromMbtiles($mbt) {
    $metadata = array();
    $this->DBconnect($mbt);
    $result = $this->db->query('select * from metadata');

    $resultdata = $result->fetchAll();
    foreach ($resultdata as $r) {
      $value = preg_replace('/(\\n)+/', '', $r['value']);
      $metadata[$r['name']] = addslashes($value);
    }
    if (!array_key_exists('minzoom', $metadata)
    || !array_key_exists('maxzoom', $metadata)
    ) {
      // autodetect minzoom and maxzoom
      $result = $this->db->query('select min(zoom_level) as min, max(zoom_level) as max from tiles');
      $resultdata = $result->fetchAll();
      if (!array_key_exists('minzoom', $metadata)){
        $metadata['minzoom'] = $resultdata[0]['min'];
      }
      if (!array_key_exists('maxzoom', $metadata)){
        $metadata['maxzoom'] = $resultdata[0]['max'];
      }
    }
    // autodetect format using JPEG magic number FFD8
    if (!array_key_exists('format', $metadata)) {
      $result = $this->db->query('select hex(substr(tile_data,1,2)) as magic from tiles limit 1');
      $resultdata = $result->fetchAll();
      $metadata['format'] = ($resultdata[0]['magic'] == 'FFD8')
        ? 'jpg'
        : 'png';
    }
    // autodetect bounds
    if (!array_key_exists('bounds', $metadata)) {
      $result = $this->db->query('select min(tile_column) as w, max(tile_column) as e, min(tile_row) as s, max(tile_row) as n from tiles where zoom_level='.$metadata['maxzoom']);
      $resultdata = $result->fetchAll();
      $w = -180 + 360 * ($resultdata[0]['w'] / pow(2, $metadata['maxzoom']));
      $e = -180 + 360 * ((1 + $resultdata[0]['e']) / pow(2, $metadata['maxzoom']));
      $n = $this->row2lat($resultdata[0]['n'], $metadata['maxzoom']);
      $s = $this->row2lat($resultdata[0]['s'] - 1, $metadata['maxzoom']);
      $metadata['bounds'] = implode(',', array($w, $s, $e, $n));
    }
    $mbt = explode('.', $mbt);
    $metadata['basename'] = $mbt[0];
    $metadata = $this->metadataValidation($metadata);
    return $metadata;
  }

  /**
   * Convert row number to latitude of the top of the row
   * @param integer $r
   * @param integer $zoom
   * @return integer
   */
   public function row2lat($r, $zoom) {
     $y = $r / pow(2, $zoom - 1 ) - 1;
     return rad2deg(2.0 * atan(exp(3.191459196 * $y)) - 1.57079632679489661922);
   }

  /**
   * Valids metaJSON
   * @param object $metadata
   * @return object
   */
  public function metadataValidation($metadata) {
    if (!array_key_exists('bounds', $metadata)) {
      $metadata['bounds'] = array(-180, -85.06, 180, 85.06);
    } elseif (!is_array($metadata['bounds'])) {
      $metadata['bounds'] = array_map('floatval', explode(',', $metadata['bounds']));
    }
    if (!array_key_exists('profile', $metadata)) {
      $metadata['profile'] = 'mercator';
    }
    if (array_key_exists('minzoom', $metadata)){
      $metadata['minzoom'] = intval($metadata['minzoom']);
    }else{
      $metadata['minzoom'] = 0;
    }
    if (array_key_exists('maxzoom', $metadata)){
      $metadata['maxzoom'] = intval($metadata['maxzoom']);
    }else{
      $metadata['maxzoom'] = 18;
    }
    if (!array_key_exists('format', $metadata)) {
      if(array_key_exists('tiles', $metadata)){
        $pos = strrpos($metadata['tiles'][0], '.');
        $metadata['format'] = trim(substr($metadata['tiles'][0], $pos + 1));
      }
    }
    $formats = $this->config['availableFormats'];
    if(!in_array(strtolower($metadata['format']), $formats)){
        $metadata['format'] = 'png';
    }
    if (!array_key_exists('scale', $metadata)) {
      $metadata['scale'] = 1;
    }
    if(!array_key_exists('tiles', $metadata)){
      $tiles = array();
      foreach ($this->config['baseUrls'] as $url) {
        $url = '' . $this->config['protocol'] . '://' . $url . '/' .
                $metadata['basename'] . '/{z}/{x}/{y}';
        if(strlen($metadata['format']) <= 4){
          $url .= '.' . $metadata['format'];
        }
        $tiles[] = $url;
      }
      $metadata['tiles'] = $tiles;
    }
    return $metadata;
  }

  /**
   * SQLite connection
   * @param string $tileset
   */
  public function DBconnect($tileset) {
    try {
      $this->db = new PDO('sqlite:' . $tileset, '', '', array(PDO::ATTR_PERSISTENT => true));
    } catch (Exception $exc) {
      echo $exc->getTraceAsString();
      die;
    }

    if (!isset($this->db)) {
      header('Content-type: text/plain');
      echo 'Incorrect tileset name: ' . $tileset;
      die;
    }
  }

  /**
   * Check if file is modified and set Etag headers
   * @param string $filename
   * @return boolean
   */
  public function isModified($filename) {
    $filename = $filename . '.mbtiles';
    $lastModifiedTime = filemtime($filename);
    $eTag = md5($lastModifiedTime);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModifiedTime) . ' GMT');
    header('Etag:' . $eTag);
    if (@strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) == $lastModifiedTime ||
            @trim($_SERVER['HTTP_IF_NONE_MATCH']) == $eTag) {
      return TRUE;
    } else {
      return FALSE;
    }
  }

  /**
   * Returns tile of dataset
   * @param string $tileset
   * @param integer $z
   * @param integer $y
   * @param integer $x
   * @param string $ext
   */
  public function renderTile($tileset, $z, $y, $x, $ext) {
    if ($this->isDBLayer($tileset)) {
      if ($this->isModified($tileset) == TRUE) {
        header('Access-Control-Allow-Origin: *');
        header('HTTP/1.1 304 Not Modified');
        die;
      }
      $this->DBconnect($tileset . '.mbtiles');
      $z = floatval($z);
      $y = floatval($y);
      $x = floatval($x);
      $result = $this->db->query('select tile_data as t from tiles where zoom_level=' . $z . ' and tile_column=' . $x . ' and tile_row=' . $y);
      $data = $result->fetchColumn();
      if (!isset($data) || $data === FALSE) {
        //if tile doesn't exist
        //select scale of tile (for retina tiles)
        $result = $this->db->query('select value from metadata where name="scale"');
        $resultdata = $result->fetchColumn();
        $scale = isset($resultdata) && $resultdata !== FALSE ? $resultdata : 1;
        $this->getCleanTile($scale, $ext);
      } else {
        $result = $this->db->query('select value from metadata where name="format"');
        $resultdata = $result->fetchColumn();
        $format = isset($resultdata) && $resultdata !== FALSE ? $resultdata : 'png';
        if ($format == 'jpg') {
          $format = 'jpeg';
        }
        if ($format == 'pbf') {
          header('Content-type: application/x-protobuf');
          header('Content-Encoding:gzip');
        } elseif ($format == 'o5m') {
          header('Content-Type: application/octet-stream');
          header('Content-Transfer-Encoding: binary');
          header('Content-Length: ' . strlen($data));
        }
        else {
          header('Content-type: image/' . $format);
        }
        header('Access-Control-Allow-Origin: *');
        echo $data;
      }
    } elseif ($this->isFileLayer($tileset)) {
      $name = './' . $tileset . '/' . $z . '/' . $x . '/' . $y;
      $mime = 'image/';
      if($ext != NULL){
        $name .= '.' . $ext;
      }
      if ($fp = @fopen($name, 'rb')) {
        if($ext != NULL){
          $mime .= $ext;
        }else{
          //detect image type from file
          $mimetypes = array('gif', 'jpeg', 'png');
          $mime .= $mimetypes[exif_imagetype($name) - 1];
        }
        header('Access-Control-Allow-Origin: *');
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($name));
        fpassthru($fp);
        die;
      }
      $this->getCleanTile($meta->scale, $ext);
    } else {
      header('HTTP/1.1 404 Not Found');
      echo 'Server: Unknown or not specified dataset "' . $tileset . '"';
      die;
    }
  }

  /**
   * Returns clean tile
   * @param integer $scale Default 1
   */
  public function getCleanTile($scale = 1, $format = 'png') {
    switch ($format) {
      case 'pbf':
        header('HTTP/1.1 404 Not Found');
        header('Content-Type: application/json; charset=utf-8');
        echo '{"message":"Tile does not exist"}';
        break;
      case 'o5m':
        header('HTTP/1.1 404 Not Found');
        header('Content-Type: application/json; charset=utf-8');
        echo '{"message":"Tile does not exist"}';
        break;
      case 'webp':
        header('Access-Control-Allow-Origin: *');
        header('Content-type: image/webp');
        echo base64_decode('UklGRhIAAABXRUJQVlA4TAYAAAAvQWxvAGs=');
        break;
      case 'jpg':
        header('Access-Control-Allow-Origin: *');
        header('Content-type: image/jpg');
        echo base64_decode('/9j/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/yQALCAABAAEBAREA/8wABgAQEAX/2gAIAQEAAD8A0s8g/9k=');
        break;
      case 'png':
      default:
        header('Access-Control-Allow-Origin: *');
        header('Content-type: image/png');
        // 256x256 transparent optimised png tile
        print_r( unpack('H', '89504e470d0a1a0a0000000d494844520000010000000100010300000066bc3a2500000003504c5445000000a77a3dda0000000174524e530040e6d8660000001f494441541819edc1010d000000c220fba77e0e37600000000000000000e70221000001f5a2bd040000000049454e44ae426082'));
        break;
    }
    die;
  }
}


/**
 * Web map tile service
 */
class Wmts extends Server {
  /**
   *
   * @param array $params
   */
  public function __construct($params) {
    parent::__construct();
    if (isset($params)) {
      parent::setParams($params);
    }
  }

  /**
   * Returns tile via WMTS specification
   */
  public function getTile() {
    $request = $this->getGlobal('Request');
    if ($request) {
      if (strpos('/', $_GET['Format']) !== FALSE) {
        $format = explode('/', $_GET['Format']);
        $format = $format[1];
      } else {
        $format = $this->getGlobal('Format');
      }
      parent::renderTile(
              $this->getGlobal('Layer'),
              $this->getGlobal('TileMatrix'),
              $this->getGlobal('TileRow'),
              $this->getGlobal('TileCol'),
              $format
              );
    } else {
      parent::renderTile($this->layer, $this->z, $this->y, $this->x, $this->ext);
    }
  }

}

/**
 * Simple router
 */
class Router {

  /**
   * @param array $routes
   */
  public static function serve($routes) {
    $request_method = strtolower($_SERVER['REQUEST_METHOD']);
    $path_info = '/';
	global $config;
	$config['protocol'] = ( isset($_SERVER["HTTPS"]) or $_SERVER['SERVER_PORT'] == '443') ? "https" : "http";
    if (!empty($_SERVER['PATH_INFO'])) {
      $path_info = $_SERVER['PATH_INFO'];
    } else if (!empty($_SERVER['ORIG_PATH_INFO']) && strpos($_SERVER['ORIG_PATH_INFO'], 'tileserver.php') === false) {
      $path_info = $_SERVER['ORIG_PATH_INFO'];
    } else if (!empty($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/tileserver.php') !== false) {
      $path_info = $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
      $config['baseUrls'][0] = $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] . '?';
    } else {
      if (!empty($_SERVER['REQUEST_URI'])) {
        $path_info = (strpos($_SERVER['REQUEST_URI'], '?') > 0) ? strstr($_SERVER['REQUEST_URI'], '?', true) : $_SERVER['REQUEST_URI'];
      }
    }
    $discovered_handler = null;
    $regex_matches = array();

    if ($routes) {
      $tokens = array(
          ':string' => '([a-zA-Z]+)',
          ':number' => '([0-9]+)',
          ':alpha' => '([a-zA-Z0-9-_@\.]+)'
      );
      //global $config;
      foreach ($routes as $pattern => $handler_name) {
        $pattern = strtr($pattern, $tokens);
        if (preg_match('#/?' . $pattern . '/?$#', $path_info, $matches)) {
          if (!isset($config['baseUrls'])) {
            $config['baseUrls'][0] = $_SERVER['HTTP_HOST'] . preg_replace('#/?' . $pattern . '/?$#', '', $path_info);
          }
          $discovered_handler = $handler_name;
          $regex_matches = $matches;
          break;
        }
      }
    }
    $handler_instance = null;
    if ($discovered_handler) {
      if (is_string($discovered_handler)) {
        if (strpos($discovered_handler, ':') !== false) {
          $discoverered_class = explode(':', $discovered_handler);
          $discoverered_method = explode(':', $discovered_handler);
          $handler_instance = new $discoverered_class[0]($regex_matches);
          call_user_func(array($handler_instance, $discoverered_method[1]));
        } else {
          $handler_instance = new $discovered_handler($regex_matches);
        }
      } elseif (is_callable($discovered_handler)) {
        $handler_instance = $discovered_handler();
      }
    }
  }

}
