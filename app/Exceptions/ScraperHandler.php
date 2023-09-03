<?php

namespace App\Exceptions;

class ScraperHandler extends CustomHandler
{
    public static function validationUrlLHandler()
    {
        return new self(message: 'URL is not valid', code: 403);
    }
}