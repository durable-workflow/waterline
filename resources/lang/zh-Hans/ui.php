<?php

return json_decode((string) file_get_contents(__DIR__.'/../zh-Hans.json'), true, flags: JSON_THROW_ON_ERROR);
