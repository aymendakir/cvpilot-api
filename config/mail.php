<?php
return ['default'=>'smtp','mailers'=>['smtp'=>['transport'=>'smtp','host'=>env('MAIL_HOST'),'port'=>env('MAIL_PORT',587),'username'=>env('MAIL_USERNAME'),'password'=>env('MAIL_PASSWORD'),'timeout'=>15]],'from'=>['address'=>env('MAIL_FROM_ADDRESS'),'name'=>'CVPilot']];
