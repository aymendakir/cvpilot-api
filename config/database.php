<?php
return ['default'=>'mysql','connections'=>['mysql'=>['driver'=>'mysql','host'=>env('DB_HOST','db'),'port'=>env('DB_PORT',3306),'database'=>env('DB_DATABASE','cvpilot'),'username'=>env('DB_USERNAME','cvpilot'),'password'=>env('DB_PASSWORD'),'charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci','prefix'=>'','strict'=>true]],'migrations'=>['table'=>'migrations']];
