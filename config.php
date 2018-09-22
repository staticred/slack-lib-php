<?php

    
    $cfg = new stdClass;
    ini_set('date.timezone','America/Vancouver');
    
    // The following values can be found on your app's General tab
    $cfg->clientid = getenv('CLIENTID') ? getenv('CLIENTID') : ''; //  Insert Client ID from App Credentials Screen
    $cfg->clientsecret = getenv('CLIENTSECRET') ? getenv('CLIENTSECRET') : ''; // // Insert Client secret from App Credentials Screen
    $cfg->token = getenv('TOKEN') ? getenv('TOKEN') : ''; //  
    $cfg->direct_install_url = "https://slack.com/oauth/authorize?&client_id={$cfg->clientid}&scope=channels:history,reactions:read,commands,groups:history,chat:write:user,groups:write,users:read,channels:write,team:read";



    $cfg->redirecturl = "https://hawkeye-channel-metrics-djh.herokuapp.com/";
    $cfg->botname = "ImABot";
    $cfg->debug = getenv('DEBUG') ? (bool) getenv('DEBUG') : FALSE;
    $cfg->webtoken = getenv('WEBTOKEN') ? getenv('WEBTOKEN') : ''; // "HeftyHippopotamus";
    $cfg->prodteam = getenv('PRODTEAM') ? getenv('PRODTEAM') : ''; // ;
    $cfg->bugchannel = getenv('BUGCHANNEL') ?  getenv('BUGCHANNEL') : ''; // 

    // Encryption
    $cfg->enctype = "aes-256-ctr";
    $cfg->encbits = "4096";
    $cfg->salt = getenv('ENCSALT') ? getenv('ENCSALT') : ''; // 

    // These shouldn't change. Please don't change them. Please. 
    $cfg->apiurl = "https://slack.com/api/";
    $cfg->oathurl = "https://slack.com/oauth/authorize";
    $cfg->oathtokenurl = getenv("PRODOAUTHTOKEN") ? getenv("PRODOAUTHTOKEN") : "";
    $cfg->useragent = "DarrensTestBot/0.1 by Darren";
    
    // Let's get a working path we can live with. 
    $pathinfo = pathinfo(realpath("config.php"));
    $cfg->basepath = $pathinfo['dirname'];
    
    
    // and set some other paths.
    $cfg->libraries = $cfg->basepath . "/lib";
    $cfg->imgdir = $cfg->basepath . "/img";


    // MySQL configuration
    $cfg->db_host = getenv('DBHOST') ? getenv('DBHOST') : ''; // 
    $cfg->db_user = getenv('DBUSER') ? getenv('DBUSER') : ''; // 
    $cfg->db_password = getenv('DBPASS') ?  getenv('DBPASS') : ''; // 
    $cfg->db_name = getenv('DBNAME') ? getenv('DBNAME') : ''; // 


    $cfg->github_token = getenv('GITHUB_TOKEN') ? getenv('GITHUB_TOKEN') : '';

/*
    $cfg->scopes = array(
      'users:read',
      'bot',
      
    );
*/
    
    $cfg->scopes = array(
                'incoming-webhook',
                'commands',
                'bot',
                'channels:read',
                'channels:history',
                'channels:write',
                'chat:write:bot',
                'chat:write:user',
                'emoji:read',
                'im:history',
                'im:write',
                'reactions:read',
                'reactions:write',
                'team:read',
                'users:read',
                'users.profile:read',
    ); 
    
// create a data directory
if (!file_exists($cfg->basepath . "/data")) {
  mkdir($cfg->basepath . "/data");
}
