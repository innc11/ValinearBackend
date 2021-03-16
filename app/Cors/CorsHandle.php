<?php

namespace Cors;

class CorsHandle
{
    public static function call()
    {
        $headers = \Utils\Utils::httpHeaders();
        $origin = isset($headers['Origin'])? $headers['Origin']:NULL;

        if(CORS_ALLOW_ALL) 
        {
            if($origin != null)
                header('Access-Control-Allow-Origin: '.$origin);
        } else {
            if($origin != null)
            {
                if(in_array($origin, CORS_ALLOW_HOSTS))
                    header('Access-Control-Allow-Origin: '.$origin);
                else
                    header('Access-Control-Allow-Origin: '.CORS_ALLOW_HOSTS[0]);
            }
        }

        header("Access-Control-Allow-Methods: ".$_SERVER['REQUEST_METHOD']);
        header("Access-Control-Allow-Credentials: true");

        // 被允许的响应中的自定义headers(在CORS场景中)
        header("Access-Control-Expose-Headers: ".implode(', ', ['X-Extra-Message']));
    }
}

?>