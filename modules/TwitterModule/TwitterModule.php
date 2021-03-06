<?php

require_once(__DIR__ . '/TwitterAPIExchange.php');

class TwitterModule extends HC_Module {

  private $twitterApiData;
  private $userData;
  private $twitterApi;
  private $loggedIn;
  private $tokensSetup;
  public function __construct($hc) {
    parent::__construct($hc);

    $this->registerWindowCallback('twitter', 'TwitterWindowCallback');
    $this->registerWindowCallback('twitter_hometimeline', 'TwitterHomeTimelineWindowCallback');
    $this->registerWindowCallback('twitter_usertimeline', 'TwitterUserTimelineWindowCallback');
    $this->registerWindowCallback('twitter_userprofile', 'TwitterUserProfileWindowCallback');
    $this->registerWindowCallback('twitter_hashtag', 'TwitterHashtagWindowCallback');
    $this->registerWindowCallback('twitter_oauth', 'TwitterOauthWindowCallback');
    $this->registerWindowCallback('twitter_admin', 'TwitterAdminWindowCallback');

    $this->registerApiCallback('twittermodule_config', 'TwitterConfigApiCallback');

    $this->logInUser();
  }

  public function logInUser() {
    $cu = $this->hc->getUserManager()->getLoggedInUser();

    if ($cu === false) {
      $this->tokensSetup = false;
      $this->loggedIn = false;
      $this->twitterApi = false;
      return;
    }

    $c_consumer_key = $this->hc->getDB()->getConfigValue('module.TwitterModule.consumer_key');
    $c_consumer_secret = $this->hc->getDB()->getConfigValue('module.TwitterModule.consumer_secret');

    if ($c_consumer_key === false || $c_consumer_secret === false) {
      $this->tokensSetup = false;
    } else {
      $this->tokensSetup = true;
    }

    $this->loggedIn = false;
    $this->twitterApi = false;

    if ($this->tokensSetup) {
      $stmt = $this->hc->getDB()->getDBo()->prepare("SELECT * FROM hc_m_TwitterModule_users WHERE user=:id");
      $stmt->bindValue(':id', $cu->getId(), PDO::PARAM_INT);
      $stmt->execute();
      $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
      if (count($rows) === 1) {
        $this->userData = $rows[0];
        $this->twitterApiData = [
          'oauth_access_token' => $rows[0]['oauth_token'],
          'oauth_access_token_secret' => $rows[0]['oauth_token_secret'],
          'consumer_key' => $c_consumer_key,
          'consumer_secret' => $c_consumer_secret,
        ];
        $this->twitterApi = new TwitterAPIExchange($this->twitterApiData);
        $this->loggedIn = true;
      }
    }
  }

  public static function setup($hc) {
    $sql = "CREATE TABLE IF NOT EXISTS hc_m_TwitterModule_users(
      user INT NOT NULL,
      oauth_token VARCHAR(256) NOT NULL,
      oauth_token_secret VARCHAR(256) NOT NULL,
      user_id VARCHAR(64) NOT NULL,
      screen_name VARCHAR(64) NOT NULL,

      PRIMARY KEY (`user`)
    )";
    $db = $hc->getDB()->getDBo();
    $stmt = $db->prepare($sql);
    return $stmt->execute();
  }

  public function onCreatingSidebar(&$sidebar) {
    $newEntry = [
      'icon' => 'twitter',
      'text' => 'Twitter',
      'id' => 'twitter',
    ];
    array_unshift($sidebar, $newEntry); // To prepend the entry
  }

  public function onCreatingNotifications(&$notifications) {
    if (!$this->loggedIn) {
      $newEntry = [
        'notificationCounter' => 1,
        'text' => 'No estás logueado en twitter',
        'cb' => 'TwitterMentionNotificationCallback',
        'cbData' => [],
        'icon' => 'twitter',
      ];
      array_unshift($notifications, $newEntry); // To prepend the entry
    }
  }

  /**
  *  Modified from: http://www.webtipblog.com/add-links-to-twitter-mentions-hashtags-and-urls-with-php-and-the-twitter-1-1-oauth-api/
  *
  * addTweetEntityLinks
  *
  * adds a link around any entities in a twitter feed
  * twitter entities include urls, user mentions, and hashtags
  *
  * @author     Joe Sexton <joe@webtipblog.com>
  * @param      object $tweet a JSON tweet object v1.1 API
  * @return     string tweet
  */
  function addTweetEntityLinks( $tweet )
  {
    // actual tweet as a string
    $tweetText = $tweet['text'];

    // create an array to hold urls
    $tweetEntites = array();

    // add each url to the array
    foreach( $tweet['entities']['urls'] as $url ) {
      $tweetEntites[] = array (
        'type'    => 'url',
        'curText' => substr( $tweetText, $url['indices'][0], ( $url['indices'][1] - $url['indices'][0] ) ),
        'newText' => "<a href='".$url['expanded_url']."' target='_blank'>".$url['display_url']."</a>"
      );
    }  // end foreach

    // add each user mention to the array
    foreach ( $tweet['entities']['user_mentions'] as $mention ) {
      $string = substr( $tweetText, $mention['indices'][0], ( $mention['indices'][1] - $mention['indices'][0] ) );
      $tweetEntites[] = array (
        'type'    => 'mention',
        'curText' => substr( $tweetText, $mention['indices'][0], ( $mention['indices'][1] - $mention['indices'][0] ) ),
        //'newText' => "<a href='http://twitter.com/".$mention['screen_name']."' target='_blank'>".$string."</a>"
        'newText' => "<span data-updatewindowboxservice='twitter_userprofile' data-cbdata-Userprofile='{$mention['screen_name']}'>".$string."</span>"
      );
    }  // end foreach

    // add each hashtag to the array
    foreach ( $tweet['entities']['hashtags'] as $tag ) {
      $string = substr( $tweetText, $tag['indices'][0], ( $tag['indices'][1] - $tag['indices'][0] ) );
      $tweetEntites[] = array (
        'type'    => 'hashtag',
        'curText' => substr( $tweetText, $tag['indices'][0], ( $tag['indices'][1] - $tag['indices'][0] ) ),
        //'newText' => "<a href='http://twitter.com/search?q=%23".$tag['text']."&src=hash' target='_blank'>".$string."</a>"
        'newText' => "<span data-updatewindowboxservice='twitter_hashtag' data-cbdata-Hashtag='{$tag['text']}'>".$string."</span>"
      );
    }  // end foreach

    // replace the old text with the new text for each entity
    foreach ( $tweetEntites as $entity ) {
      $tweetText = str_replace( $entity['curText'], $entity['newText'], $tweetText );
    } // end foreach

    return $tweetText;

  } // end addTweetEntityLinks()

  private function tweetsToHtmlTable($tweets) {
    $tweetsHtmlFormatted = "<table class='table table-responsive twittermodule_tweettable'>";
    foreach ($tweets as $tweet) {
      $tweetText = $this->addTweetEntityLinks($tweet);
      $tweetsHtmlFormatted .= "<tr><td><p data-updatewindowboxservice='twitter_userprofile' data-cbdata-Userprofile='{$tweet['user']['screen_name']}'>{$tweet['user']['screen_name']}</p>{$tweetText}<br>{$tweet['created_at']}</td></tr>";
    }
    $tweetsHtmlFormatted .= "</table>";
    return $tweetsHtmlFormatted;
  }

  public function TwitterWindowCallback() {
    if (!$this->loggedIn) return $this->TwitterOauthWindowCallback();
    $html = '<ul class="nav nav-tabs">
              <li><a href="#" data-updatewindowboxservice="twitter_hometimeline">Timeline</a></li>
              <li><a href="#" data-updatewindowboxservice="twitter_userprofile">Profile</a></li>
              <li><a href="#" data-updatewindowboxservice="twitter_admin">Configure</a></li>
            </ul>
    ';
    return [
      'html' => $html,
      'title' => '
      <svg class="icon twitter windowicon">
         <use xlink:href="#twitter">
         </use>
      </svg> <span class="glyphicon glyphicon-menu-hamburger navbar-element"></span>
      Twitter',
    ];
  }

  public function TwitterHomeTimelineWindowCallback() {
    if (!$this->loggedIn) return $this->TwitterOauthWindowCallback();

    $url = 'https://api.twitter.com/1.1/statuses/home_timeline.json';
    $getfield = '?count=10';
    $tweets = json_decode($this->twitterApi->setGetfield($getfield)->buildOauth($url, 'GET')->performRequest(), true);
    $tweetsHtmlFormatted = $this->tweetsToHtmlTable($tweets);

    $html = '<ul class="nav nav-tabs">
              <li class="active"><a href="#" data-updatewindowboxservice="twitter_hometimeline">Timeline</a></li>
              <li><a href="#" data-updatewindowboxservice="twitter_userprofile">Profile</a></li>
              <li><a href="#" data-updatewindowboxservice="twitter_admin">Configure</a></li>
            </ul>
    ';
    $html .= $tweetsHtmlFormatted;

    return [
      'html' => $html,
      'title' => '
      <svg class="icon twitter windowicon">
         <use xlink:href="#twitter">
         </use>
      </svg> <span class="glyphicon glyphicon-time navbar-element"></span>
      Twitter Home Timeline',
    ];
  }

  public function TwitterUserTimelineWindowCallback($fields = []) {
    if (!$this->loggedIn) return $this->TwitterOauthWindowCallback();
    if (empty($fields['Usertimeline'])) return $this->TwitterWindowCallback();

    $userTimeline = $fields['Usertimeline'];
    $url = 'https://api.twitter.com/1.1/statuses/user_timeline.json';
    $getfield = '?screen_name='.$userTimeline.'&count=10';
    $tweets = json_decode($this->twitterApi->setGetfield($getfield)
      ->buildOauth($url, 'GET')
      ->performRequest(), true);
    $tweetsHtmlFormatted = $this->tweetsToHtmlTable($tweets);

    $html = '<ul class="nav nav-tabs">
              <li><a href="#" data-updatewindowboxservice="twitter_hometimeline">Timeline</a></li>
              <li><a href="#" data-updatewindowboxservice="twitter_userprofile">Profile</a></li>
              <li><a href="#" data-updatewindowboxservice="twitter_admin">Configure</a></li>
            </ul>
    ';
    $html .= $tweetsHtmlFormatted;
    return [
      'html' => $html,
      'title' => '
      <svg class="icon twitter windowicon">
         <use xlink:href="#twitter">
         </use>
      </svg> <span class="glyphicon glyphicon-time navbar-element"></span>
      Twitter User Timeline',
    ];
  }

  public function TwitterUserProfileWindowCallback($fields = []) {
    if (!$this->loggedIn) return $this->TwitterOauthWindowCallback();

    $profile = $this->userData['screen_name'];
    if (!empty($fields['Userprofile'])) $profile = $fields['Userprofile'];

    $html = '<ul class="nav nav-tabs">
              <li><a href="#" data-updatewindowboxservice="twitter_hometimeline">Timeline</a></li>
              <li class="active"><a href="#" data-updatewindowboxservice="twitter_userprofile">Profile</a></li>
              <li><a href="#" data-updatewindowboxservice="twitter_admin">Configure</a></li>
            </ul>
    ';

    $url = 'https://api.twitter.com/1.1/users/show.json';
    $getfield = '?screen_name='.$profile;
    $profileData = json_decode($this->twitterApi->setGetfield($getfield)
      ->buildOauth($url, 'GET')
      ->performRequest(), true);

    $html .= "<img src='{$profileData['profile_image_url']}' class='twittermodule_profilepic'>";
    $html .= "<br><p><a href='https://twitter.com/{$profileData['screen_name']}' target='_blank'>@{$profileData['screen_name']}</a> ({$profileData['name']})</p><br>";
    if (!empty($profileData['description'])) {
      $html .= "<p>{$profileData['description']}</p>";
    }
    if (!empty($profileData['url'])) {
      $html .= "<p><a href='{$profileData['url']}' target='_blank'>Web</a></p>";
    }


    $html .= "<p data-updatewindowboxservice='twitter_usertimeline' data-cbdata-Usertimeline='{$profile}'>Ir al timeline de {$profileData['name']}</p>";

    return [
      'html' => $html,
      'title' => '
      <svg class="icon twitter windowicon">
         <use xlink:href="#twitter">
         </use>
      </svg> <span class="glyphicon glyphicon-user navbar-element"></span>
      Twitter User Profile',
    ];
  }

  public function TwitterHashtagWindowCallback($fields = []) {
    if (!$this->loggedIn) return $this->TwitterOauthWindowCallback();

    if (empty($fields['Hashtag'])) return $this->TwitterWindowCallback();
    $html = '<ul class="nav nav-tabs">
              <li><a href="#" data-updatewindowboxservice="twitter_hometimeline">Timeline</a></li>
              <li><a href="#" data-updatewindowboxservice="twitter_userprofile">Profile</a></li>
              <li><a href="#" data-updatewindowboxservice="twitter_admin">Configure</a></li>
            </ul>
    ';

    $query = "#{$fields['Hashtag']}";

    $html .= "<p>$query</p>";
    $url = 'https://api.twitter.com/1.1/search/tweets.json';
    $getfield = '?q='.urlencode($query);
    $queryData = json_decode($this->twitterApi->setGetfield($getfield)
      ->buildOauth($url, 'GET')
      ->performRequest(), true);

    $tweetsHtmlFormatted = "<table class='table table-responsive twittermodule_tweettable'>";
    foreach ($queryData['statuses'] as $i => $tweet) {
      $tweetText = $this->addTweetEntityLinks($tweet);
      $tweetsHtmlFormatted .= "<tr><td>{$tweetText}<br>{$tweet['created_at']}</td></tr>";
      if ($i >= 5) break;
    }
    $tweetsHtmlFormatted .= "</table>";

    $html .= $tweetsHtmlFormatted;

    return [
      'html' => $html,
      'title' => '
      <svg class="icon twitter windowicon">
         <use xlink:href="#twitter">
         </use>
      </svg> <span class="glyphicon glyphicon-filter navbar-element"></span>
      Twitter Hashtag',
    ];
  }

  public function TwitterOauthWindowCallback($fields = []) {
    $cu = $this->hc->getUserManager()->getLoggedInUser();
    if ($this->loggedIn) return $this->TwitterWindowCallback();
    if (!$this->tokensSetup) {
      if ($cu === false || !$cu->isAdmin()) {
        return [
          'html' => '<p>Not configured. Plase, ask an administrator to configure this module.</p>',
          'title' => '
          <svg class="icon twitter windowicon">
             <use xlink:href="#twitter">
             </use>
          </svg> <span class="glyphicon glyphicon-ban-circle navbar-element"></span>
          Twitter (not available)',
        ];
      } else {
        return [
          'html' => '<p>Not configured. Plase, <span data-updatewindowboxservice="twitter_admin">configure</span> this module.</p>',
          'title' => '
          <svg class="icon twitter windowicon">
             <use xlink:href="#twitter">
             </use>
          </svg> <span class="glyphicon glyphicon-ban-circle navbar-element"></span>
          Twitter (not available)',
        ];
      }
    }
    $html = '
    <script>
    var popupwindow;
    function startLogin() {
      console.log("Start login");
      popupwindow = window.open("modules/TwitterModule/TwitterOauth.php","TwitterOauth","width=550,height=650,0,status=0,");
    }

    function endLogin() {
      console.log("Endlogin");
      popupwindow.close();
      var wbox = $("#twittermodule_oauth_loginbutton").closest(".userview-content-column").closest(".userview-content-column-wrapper");
      setBoxContents(wbox, "twitter");
    }
    </script>
    <a onclick="">
      <input type="submit" value="LOGIN EN TWITTER" onclick="startLogin()" id="twittermodule_oauth_loginbutton" />
    </a>';
    return [
      'html' => $html,
      'title' => '
      <svg class="icon twitter windowicon">
         <use xlink:href="#twitter">
         </use>
      </svg> <span class="glyphicon glyphicon-log-in navbar-element"></span>
      Twitter Login',
    ];
  }

  public function TwitterAdminWindowCallback($fields = []) {
    $cu = $this->hc->getUserManager()->getLoggedInUser();

    $html = '<ul class="nav nav-tabs">
              <li><a href="#" data-updatewindowboxservice="twitter_hometimeline">Timeline</a></li>
              <li><a href="#" data-updatewindowboxservice="twitter_userprofile">Profile</a></li>
              <li class="active"><a href="#" data-updatewindowboxservice="twitter_admin">Configure</a></li>
            </ul>
    ';

    if ($cu === false || !$cu->isAdmin()) {
      return [
        'html' => $html.'<p>Forbidden</p><p>Only administrators can access this view</p>',
        'title' => '
        <svg class="icon twitter windowicon">
           <use xlink:href="#twitter">
           </use>
        </svg> <span class="glyphicon glyphicon-ban-circle navbar-element"></span>
        Twitter (forbidden access)',
      ];
    }



    if ($this->tokensSetup) {
      $html .= '<p>Already setup</p>';
    } else {
      $html .= '<p>Not setup</p>';
    }

    $html .= '
    <p>Create a new app in <a href="https://apps.twitter.com/" target="_blank">Twitter Application Management</a></p>
    <p>Access the data in the tab "Keys and Access Tokens".</p><br>
    <input type="text" name="twittermodule_config_oauth_access_token" placeholder="Access Token" class="twittermodule_admininput"><br>
    <input type="text" name="twittermodule_config_oauth_access_token_secret" placeholder="Access Token Secret" class="twittermodule_admininput"><br>
    <input type="text" name="twittermodule_config_consumer_key" placeholder="Consumer Key (API Key)" class="twittermodule_admininput"><br>
    <input type="text" name="twittermodule_config_consumer_secret" placeholder="Consumer Secret (API Secret)" class="twittermodule_admininput"><br>
    <p id="twittermodule_config_message"></p>
    <input type="submit" value="Save Configuration" id="twittermodule_config_submit" onclick="twittermodule_config_submit()">

    ';

    return [
      'html' => $html,
      'title' => '
      <svg class="icon twitter windowicon">
         <use xlink:href="#twitter">
         </use>
      </svg> <span class="glyphicon glyphicon-cog navbar-element"></span>
      Twitter Admin',
    ];
  }
  public function TwitterConfigApiCallback($identifier, $data, $cbdata) {
    $cu = $this->hc->getUserManager()->getLoggedInUser();

    if ($cu === false || !$cu->isAdmin()) {
      return [false];
    }
    $this->twitterApiData = [
      'oauth_access_token' => $data['oauth_access_token'],
      'oauth_access_token_secret' => $data['oauth_access_token_secret'],
      'consumer_key' => $data['consumer_key'],
      'consumer_secret' => $data['consumer_secret'],
    ];
    $this->twitterApi = new TwitterAPIExchange($this->twitterApiData);

    $url = 'https://api.twitter.com/1.1/account/verify_credentials.json';
    $status = json_decode($this->twitterApi->buildOauth($url, 'GET')->performRequest(), true);

    if (!empty($status['errors']) && !empty($status['errors'][0])) {
      return [
        'status' => 'error',
        'msg' => "({$status['errors'][0]['code']}) {$status['errors'][0]['message']}",
      ];
    } else if(!empty($status['screen_name'])) {
      $this->hc->getDB()->setConfigValue('module.TwitterModule.oauth_access_token', $data['oauth_access_token']);
      $this->hc->getDB()->setConfigValue('module.TwitterModule.oauth_access_token_secret', $data['oauth_access_token_secret']);
      $this->hc->getDB()->setConfigValue('module.TwitterModule.consumer_key', $data['consumer_key']);
      $this->hc->getDB()->setConfigValue('module.TwitterModule.consumer_secret', $data['consumer_secret']);
      return [
        'status' => 'ok',
        'msg' => "Authenticated as {$status['screen_name']}",
      ];
    } else {
      return [
        'status' => 'error',
        'msg' => "unknown_error",
      ];
    }
  }

  public function onCreatingMetacode(&$metacode) {
    $metacode[] = '<script>
  function twittermodule_config_submit() {
    var b = $("#twittermodule_config_submit");
    $.ajax({
      type: "POST",
      url: "api.php?action=twittermodule_config",
      dataType: "json",
      data: {
        "oauth_access_token": $("input[name=\'twittermodule_config_oauth_access_token\']").val(),
        "oauth_access_token_secret": $("input[name=\'twittermodule_config_oauth_access_token_secret\']").val(),
        "consumer_key": $("input[name=\'twittermodule_config_consumer_key\']").val(),
        "consumer_secret": $("input[name=\'twittermodule_config_consumer_secret\']").val(),
      },
      success: function(data) {
        $("#twittermodule_config_message").html(data["msg"]);
        if (data["status"] == "ok") {
          console.log("twitter configured ok");
          setTimeout(function(){
            var wbox = $(b).closest(".userview-content-column").closest(".userview-content-column-wrapper");
            setBoxContents(wbox, "twitter");
          }, 1000);
        }
      },
    });
  }
  </script>';
    $metacode[] = '<link rel="stylesheet" href="modules/TwitterModule/style.css">';
  }

}
