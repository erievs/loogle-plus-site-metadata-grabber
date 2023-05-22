<?php
/*

PHP Grab Favicon
================

> This `PHP Favicon Grabber` use a given url, save a copy (if wished) and return the image path.

How it Works
------------

1. Check if the favicon already exists local or no save is wished, if so return path & filename
2. Else load URL and try to match the favicon location with regex
3. If we have a match the favicon link will be made absolute
4. If we have no favicon we try to get one in domain root
5. If there is still no favicon we randomly try google, faviconkit & favicongrabber API
6. If favicon should be saved try to load the favicon URL
7. If wished save the Favicon for the next time and return the path & filename

How to Use
----------

```PHP
$url = 'example.com';

$grap_favicon = array(
'URL' => $url,   // URL of the Page we like to get the Favicon from
'SAVE'=> true,   // Save Favicon copy local (true) or return only favicon url (false)
'DIR' => './',   // Local Dir the copy of the Favicon should be saved
'TRY' => true,   // Try to get the Favicon frome the page (true) or only use the APIs (false)
'OVR' => false,  // Skip if file is already local (false) or overwrite (true)
'DEV' => null,   // Give all Debug-Messages ('debug') or only make the work (null)
);

echo '<img src="'.grap_favicon($grap_favicon).'">';
```

Todo
----
Optional split the download dir into several sub-dirs (MD5 segment of filename e.g. /af/cd/example.com.png) if there are a lot of favicons.

Infos about Favicon
-------------------
https://github.com/audreyr/favicon-cheat-sheet

###### Copyright 2019-2023 Igor Gaffling

*/

$time_start = microtime(true);

/* Defaults */

$debug = null;
$consoleMode = false;
$overWrite = false;
$localPath = "./";
$saveLocal = true;
$tryHomepage = true;
$testURLs = array();

/* Detect Console Mode, can be overridden with switches */

if (php_sapi_name() == "cli") { $consoleMode = true; }

if ($consoleMode) { $script_name = basename(__FILE__); } else { $script_name = basename($_SERVER['PHP_SELF']); }

/* Command Line Options */
$shortopts  = "";
$shortopts  = "l::";
$shortopts  = "p::";
$shortopts .= "h?";

$longopts  = array(
  "list::",
  "path::",
  "user-agent::",
  "curl-timeout::",
  "tryhomepage",
  "onlyuseapis",
  "store",
  "nostore",
  "save",
  "nosave",
  "overwrite",
  "skip",
  "curl-verbose",
  "consolemode",
  "noconsolemode",
  "debug",
  "help",
);


$options = getopt($shortopts, $longopts);

if ((isset($options['help'])) || (isset($options['h'])) || (isset($options['?'])))
{
  echo "Usage: $script_name (Switches)\n\n";
  echo "--list=FILE/LIST            Filename or a delimited list of URLs to check.  Lists can be separated with space, comma or semi-colon.\n";
  echo "--path=PATH                 Location to store icons (default is $localPath)\n";
  echo "\n";
  echo "--tryhomepage               Try homepage first, then APIs.  (default is true)\n";
  echo "--onlyuseapis               Only use APIs.\n";
  echo "--store                     Store favicons locally. (default is true)\n";
  echo "--nostore                   Do not store favicons locally.\n";
  echo "--overwrite                 Overwrite local favicons (default is false)\n";
  echo "--skip                      Skip local favicons if they are already present. (default is true)\n";
  echo "--consolemode               Force console output.\n";
  echo "--noconsolemode             Force HTML output.\n";
  echo "--debug                     Enable debug messages.\n";
  echo "--user-agent=AGENT_STRING   Customize the user agent.\n";
  echo "--curl-verbose              Enable cURL verbose.\n";
  echo "--curl-timeout=SECONDS      Set cURL timeeout (default is 60).\n";
  echo "\n";
  exit;
}

/* Process Options */

$opt_list = null;
$opt_localpath = null;
$opt_usestdin = null;
$opt_tryhomepage = null;
$opt_storelocal = null;
$opt_debug = null;
$opt_console = null;
$opt_timeout = null;
$opt_curl_user_agent = null;
$opt_curl_verbose = false;
$opt_curl_timeout = null;

if (isset($options['debug'])) { $opt_debug = true; }
if (isset($options['list'])) { $opt_list = $options['list']; }
if (isset($options['path'])) { $opt_localpath = $options['path']; }
if (isset($options['l'])) { $opt_list = $options['l']; }
if (isset($options['p'])) { $opt_localpath = $options['p']; }
if (isset($options['consolemode'])) { $opt_console = true; }
if (isset($options['store'])) { $opt_storelocal = true; }
if (isset($options['save'])) { $opt_storelocal = true; }
if (isset($options['skip'])) { $overWrite = false; }
if (isset($options['tryhomepage'])) { $opt_tryhomepage = true; }
if (isset($options['nostore'])) { $opt_storelocal = false; }
if (isset($options['nosave'])) { $opt_storelocal = false; }
if (isset($options['onlyuseapis'])) { $opt_tryhomepage = false; }
if (isset($options['noconsolemode'])) { $opt_console = false; }
if (isset($options['overwrite'])) { $overWrite = true; }
if (isset($options['user-agent'])) { $opt_curl_user_agent = $options['user-agent']; }
if (isset($options['curl-verbose'])) { $opt_curl_verbose = true; }
if (isset($options['curl-timeout'])) { $opt_curl_timeout = $options['curl-timeout']; }

if (!is_null($opt_localpath)) { if (file_exists($opt_localpath)) { $localPath = $opt_localpath; } }
if (!is_null($opt_tryhomepage)) { $tryHomepage = $opt_tryhomepage; }
if (!is_null($opt_storelocal)) { $saveLocal = $opt_storelocal; }
if (!is_null($opt_debug)) { if ($opt_debug) { $debug = "debug"; } else { $debug = null; } }
if (!is_null($opt_console)) { $consoleMode = $opt_console; }
if (!is_null($opt_curl_timeout)) { if (is_numeric($opt_curl_timeout)) { if ($opt_curl_timeout >= 0 && $opt_curl_timeout < 600) { $opt_timeout = $opt_curl_timeout; } } }

if (isset($opt_list)) {
  if (file_exists($opt_list)) {
    $testURLs = file($opt_list,FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  } else {
    if (count($testURLs) == 0) {
      $testURLs = explode(",",str_replace(array(",",";"," "),",",$opt_list));
    }
  }
}

if (is_null($opt_curl_user_agent)) { if (isset($_SERVER['SERVER_NAME'])) { $opt_curl_user_agent = 'FaviconBot/1.0 (+http://'.$_SERVER['SERVER_NAME'].'/'; } else { $opt_curl_user_agent = 'FaviconBot/1.0/'; } }
if (strtolower($opt_curl_user_agent) != "none") { setGlobal('curl_useragent', $opt_curl_user_agent); }
setGlobal('curl_verbose', $opt_curl_verbose);
if (!is_null($opt_timeout)) { setGlobal('curl_timeout', $opt_timeout); }

if (count($testURLs) == 0) {
  $testURLs = array(
    'http://aws.amazon.com',
    'http://www.apple.com',
    'http://www.dribbble.com',
    'http://www.github.com',
    'http://www.intercom.com',
    'http://www.indiehackers.com',
    'http://www.medium.com',
    'http://www.mailchimp.com',
    'http://www.netflix.com',
    'http://www.producthunt.com',
    'http://www.reddit.com',
    'http://www.slack.com',
    'http://www.soundcloud.com',
    'http://www.stackoverflow.com',
    'http://www.techcrunch.com',
    'http://www.trello.com',
    'http://www.vimeo.com',
    'https://www.whatsapp.com/',
    'https://www.gaffling.com/',
  );
}

foreach ($testURLs as $url) {
  $grap_favicon = array(
    'URL' => $url,          // URL of the Page we like to get the Favicon from
    'SAVE'=> $saveLocal,    // Save Favicon copy local (true) or return only favicon url (false)
    'DIR' => $localPath,    // Local Dir the copy of the Favicon should be saved
    'TRY' => $tryHomepage,  // Try to get the Favicon frome the page (true) or only use the APIs (false)
    'OVR' => $overWrite,    // Overwrite existing local files or skip
    'DEV' => $debug,        // Give all Debug-Messages ('debug') or only make the work (null)
  );
  $favicons[] = grap_favicon($grap_favicon, $consoleMode);
}

foreach ($favicons as $favicon) {
  if (!empty($favicon))
  {
    if ($consoleMode) {
      echo "Icon: $favicon\n";
    } else {
      echo '<img title="'.$favicon.'" style="width:32px;padding-right:32px;" src="'.$favicon.'">';
    }
  }
}

if ($consoleMode) {
  echo "\nRuntime: ".round(microtime(true)-$time_start,2)." Sec.\n";
} else {
  echo '<br><br><tt>Runtime: '.round((microtime(true)-$_SERVER["REQUEST_TIME_FLOAT"]),2).' Sec.';
}

/*  FUNCTIONS */
function grap_favicon($options=array(), $consoleMode = false) {
  if (!$consoleMode) {
    // avoid script runtime timeout
    $max_execution_time = ini_get("max_execution_time");
    set_time_limit(0); // 0 = no timelimit
  }

  $curlTimeout = getGlobal('curl_timeout');

  // Ini Vars
  $url       = (isset($options['URL']))?$options['URL']:'gaffling.com';
  $save      = (isset($options['SAVE']))?$options['SAVE']:true;
  $directory = (isset($options['DIR']))?$options['DIR']:'./';
  $trySelf   = (isset($options['TRY']))?$options['TRY']:true;
  $DEBUG     = (isset($options['DEV']))?$options['DEV']:null;
  $overwrite = (isset($options['OVR']))?$options['OVR']:false;

  // URL to lower case
	$url = strtolower($url);

	// Get the Domain from the URL
  $domain = parse_url($url, PHP_URL_HOST);

  // Check Domain
  $domainParts = explode('.', $domain);
  if(count($domainParts) == 3 and $domainParts[0]!='www') {
    // With Subdomain (if not www)
    $domain = $domainParts[0].'.'.
              $domainParts[count($domainParts)-2].'.'.$domainParts[count($domainParts)-1];
  } else if (count($domainParts) >= 2) {
    // Without Subdomain
		$domain = $domainParts[count($domainParts)-2].'.'.$domainParts[count($domainParts)-1];
	} else {
	  // Without http(s)
	  $domain = $url;
	}

	// FOR DEBUG ONLY
  if ($consoleMode) {
    if($DEBUG=='debug')echo "Domain: $domain\n";
  } else {
    if($DEBUG=='debug')print('<b style="color:red;">Domain</b> #'.@$domain.'#<br>');
  }

  // If $trySelf == TRUE ONLY USE APIs
  if (isset($trySelf) && $trySelf == TRUE) {

    // Load Page
    $html = load($url, $DEBUG, $consoleMode, $curlTimeout);

    if (empty($html)) {
      if ($consoleMode) {
        if($DEBUG=='debug')echo "No data received\n";
      }
    } else {
      if ($consoleMode) {
        if($DEBUG=='debug')echo "Attempting RegEx Match\n";
      }
      // Find Favicon with RegEx
      $regExPattern = '/((<link[^>]+rel=.(icon|shortcut\sicon|alternate\sicon)[^>]+>))/i';
      if (@preg_match($regExPattern, $html, $matchTag)) {
        if ($consoleMode) {
          if($DEBUG=='debug')echo "RegEx Initial Pattern Matched\n";
          if($DEBUG=='debug')print_r($matchTag) . "\n";
        }
        $regExPattern = '/href=(\'|\")(.*?)\1/i';
        if (isset($matchTag[1]) && @preg_match($regExPattern, $matchTag[1], $matchUrl)) {
          if ($consoleMode) {
            if($DEBUG=='debug')echo "RegEx Secondary Pattern Matched\n";
          }
          if (isset($matchUrl[2])) {
            if ($consoleMode) {
              if($DEBUG=='debug')echo "Found Match, Building Link\n";
            }

            // Build Favicon Link
            $favicon = rel2abs(trim($matchUrl[2]), 'http://'.$domain.'/');

            // FOR DEBUG ONLY
            if ($consoleMode) {
              if($DEBUG=='debug')echo "Match $favicon\n";
            } else {
              if($DEBUG=='debug')print('<b style="color:red;">Match</b> #'.@$favicon.'#<br>');
            }
          } else {
            if ($consoleMode) {
              if($DEBUG=='debug')echo "Failed To Find Match\n";
            }
          }
        } else {
          if ($consoleMode) {
            if($DEBUG=='debug')echo "RegEx Secondary Pattern Failed To Match\n";
          }
        }
      } else {
        if ($consoleMode) {
          if($DEBUG=='debug')echo "RegEx Initial Pattern Failed To Match\n";
        }
      }
    }

    // If there is no Match: Try if there is a Favicon in the Root of the Domain
    if (empty($favicon)) {
      $favicon = 'http://'.$domain.'/favicon.ico';
      if ($consoleMode) {
        if($DEBUG=='debug')echo "Attempting Direct Match\n";
      }

      // Try to Load Favicon
      # if ( !@getimagesize($favicon) ) {
      # https://www.php.net/manual/en/function.getimagesize.php
      # Do not use getimagesize() to check that a given file is a valid image.
      if ($consoleMode) {
        if($DEBUG=='debug')echo "$favicon\n";
      }
      $fileExtension = geticonextension($favicon,false);
      if (is_null($fileExtension)) {
        unset($favicon);
        if ($consoleMode) {
          if($DEBUG=='debug')echo "Failed Direct Match\n";
        }
      }
    }
  } // END If $trySelf == TRUE ONLY USE APIs

  // If nothink works: Get the Favicon from API
  if ((!isset($favicon)) || (empty($favicon))) {
    if ($consoleMode) {
      if($DEBUG=='debug')echo "Attempting API Match\n";
    }

    // Select API by Random
    $random = rand(1,3);

    // Faviconkit API
    if (($random == 1) || (empty($favicon))) {
      if ($consoleMode) {
        if($DEBUG=='debug')echo "API: Selected FavIconKit\n";
      }
      $favicon = 'https://api.faviconkit.com/'.$domain.'/16';
    }

    // Favicongrabber API
    if (($random == 2) || (empty($favicon))) {
      if ($consoleMode) {
        if($DEBUG=='debug')echo "API: Selected FavIconGrabber\n";
      }
      $echo = json_decode(load('http://favicongrabber.com/api/grab/'.$domain,FALSE),TRUE);

      // Get Favicon URL from Array out of json data (@ if something went wrong)
      $favicon = @$echo['icons']['0']['src'];
    }

    // Google API (check also md5() later)
    if ($random == 3) {
      if ($consoleMode) {
        if($DEBUG=='debug')echo "API: Selected Google\n";
      }
      $favicon = 'http://www.google.com/s2/favicons?domain='.$domain;
    }

    // FOR DEBUG ONLY
    if ($consoleMode) {
      if($DEBUG=='debug')echo "API ($random): Result: $favicon\n";
    } else {
      if($DEBUG=='debug')print('<b style="color:red;">'.$random.'. API</b> #'.@$favicon.'#<br>');
    }

  } // END If nothink works: Get the Favicon from API


  // If Favicon should be saved
  if ((isset($save)) && ($save == TRUE)) {
    unset($content);

    if ($consoleMode) {
      if($DEBUG=='debug')echo "Attempting to load favicon\n";
    }

    //  Load Favicon
    $content = load($favicon, $DEBUG, $consoleMode, $curlTimeout);

    if (empty($content)) {
      if ($consoleMode) {
        if($DEBUG=='debug')echo "Failed to load favicon\n";
      }
    } else {
      // If Google API don't know and deliver a default Favicon (World)
      if (isset($random) && $random == 3 && md5($content) == '3ca64f83fdcf25135d87e08af65e68c9') {
        $domain = 'default'; // so we don't save a default icon for every domain again

        // FOR DEBUG ONLY
        if ($consoleMode) {
          if($DEBUG=='debug')echo "Google: #use default icon#\n";
        } else {
          if($DEBUG=='debug')print('<b style="color:red;">Google</b> #use default icon#<br>');
        }
      }

      //  Get Type
      if (!empty($favicon)) {
        $fileExtension = geticonextension($favicon);
        if (is_null($fileExtension)) {
          if ($consoleMode) {
            if($DEBUG=='debug')echo "Invalid File Type for $favicon\n";
          } else {
            if($DEBUG=='debug')print('<b style="color:red;">Write-File</b> #INVALID_IMAGE#<br>');
          }
        } else {
          $filePath = preg_replace('#\/\/#', '/', $directory.'/'.$domain.'.'.$fileExtension);

          //  If overwrite, delete it
          if (file_exists($filePath)) { if ($overwrite) { unlink($filePath); } }

          //  If file exists, skip
          if (file_exists($filePath)) {
            // FOR DEBUG ONLY
            if ($consoleMode) {
              if($DEBUG=='debug')echo "Skipping File $filePath\n";
            } else {
              if($DEBUG=='debug')print('<b style="color:red;">Skip-File</b> #'.@$filePath.'#<br>');
            }
          } else {
            // Write
            $fh = @fopen($filePath, 'wb');
            fwrite($fh, $content);
            fclose($fh);
            // FOR DEBUG ONLY
            if ($consoleMode) {
              if($DEBUG=='debug')echo "Writing File $filePath\n";
            } else {
              if($DEBUG=='debug')print('<b style="color:red;">Write-File</b> #'.@$filePath.'#<br>');
            }
          }
        }
      }
    }
  } else {
    // Don't save Favicon local, only return Favicon URL
    $filePath = $favicon;
  }

	// FOR DEBUG ONLY
	if ($DEBUG=='debug') {
    // Load the Favicon from local file
    if (!empty($filePath)) {
      if (!function_exists('file_get_contents')) {
        $fh = @fopen($filePath, 'r');
        while (!feof($fh)) {
          $content .= fread($fh, 128); // Because filesize() will not work on URLS?
        }
        fclose($fh);
      } else {
        $content = file_get_contents($filePath);
      }
      if ($consoleMode) {
        echo geticonextension($filePath) . " format file loaded from $filePath\n";
      } else {
      print('<b style="color:red;">Image</b> <img style="width:32px;"
             src="data:image/png;base64,'.base64_encode($content).'"><hr size="1">');
      }
    }
  }


  if (!$consoleMode) {
    // reset script runtime timeout
    set_time_limit($max_execution_time); // set it back to the old value
  }

  // Return Favicon Url
  return $filePath;

} // END MAIN Function

/* HELPER load use curl or file_get_contents (both with user_agent) and fopen/fread as fallback */
function load($url, $DEBUG, $consoleMode = false, $timeOut = 60) {
  if (function_exists('curl_version')) {
    if (!isset($timeOut)) { $timeOut = 60; }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_USERAGENT, getGlobal('curl_useragent'));
    curl_setopt($ch, CURLOPT_VERBOSE, getGlobal('curl_verbose'));
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeOut);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $content = curl_exec($ch);
    if ( $DEBUG=='debug' ) { // FOR DEBUG ONLY
      $http_code = curl_getinfo($ch);
      if ($consoleMode) {
        echo "cURL: '$url' ".$http_code['http_code']."\n";
      } else {
        print('<b style="color:red;">cURL</b> #'.$http_code['http_code'].'#<br>');
      }
    }
    curl_close($ch);
    unset($ch);
  } else {
  	$context = array ( 'http' => array (
        'user_agent' => 'FaviconBot/1.0 (+http://'.$_SERVER['SERVER_NAME'].'/)'),
    );
    $context = stream_context_create($context);
	  if (!function_exists('file_get_contents')) {
      $fh = fopen($url, 'r', FALSE, $context);
      $content = '';
      while (!feof($fh)) {
        $content .= fread($fh, 128); // Because filesize() will not work on URLS?
      }
      fclose($fh);
    } else {
      $content = file_get_contents($url, NULL, $context);
    }
  }
  return $content;
}

/* HELPER: Change URL from relative to absolute */
function rel2abs($rel, $base) {
	extract(parse_url($base));
	if (strpos( $rel,"//" ) === 0) return $scheme . ':' . $rel;
	if (parse_url( $rel, PHP_URL_SCHEME ) != '') return $rel;
	if ($rel[0] == '#' or $rel[0] == '?') return $base . $rel;
	$path = preg_replace( '#/[^/]*$#', '', $path);
	if ($rel[0] ==  '/') $path = '';
	$abs = $host . $path . "/" . $rel;
	$abs = preg_replace( "/(\/\.?\/)/", "/", $abs);
	$abs = preg_replace( "/\/(?!\.\.)[^\/]+\/\.\.\//", "/", $abs);
	return $scheme . '://' . $abs;
}

/* GET ICON IMAGE TYPE  */
function geticonextension($url, $noFallback = false) {
  $retval = null;
  if (!empty($url))
  {
    // If exif_imagetype is not available, it will simply return the extension
    if (function_exists('exif_imagetype')) {
      $filetype = @exif_imagetype($url);
      if ($filetype) {
        if ($filetype == IMAGETYPE_GIF) { $retval = "gif"; }
        if ($filetype == IMAGETYPE_JPEG) { $retval = "jpg"; }
        if ($filetype == IMAGETYPE_PNG) { $retval = "png"; }
        if ($filetype == IMAGETYPE_ICO) { $retval = "ico"; }
        if ($filetype == IMAGETYPE_WEBP) { $retval = "webp"; }
        if ($filetype == IMAGETYPE_BMP) { $retval = "bmp"; }
        if ($filetype == IMAGETYPE_GIF) { $retval = "gif"; }
      }
    } else {
      if (!$noFallback) { $retval = @preg_replace('/^.*\.([^.]+)$/D', '$1', $url); }
    }
  }
  return $retval;
}

function setGlobal($variable,$value = null) {
	$GLOBALS[$variable] = $value;
}

function getGlobal($variable) {
	return $GLOBALS[$variable];
}
