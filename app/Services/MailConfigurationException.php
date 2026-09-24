<?php
namespace App\Services;

// Only messages authored by the application may be shown directly to administrators.
class MailConfigurationException extends \RuntimeException {}
