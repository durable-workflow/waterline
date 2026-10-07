<?php

return json_decode((string) file_get_contents(__DIR__.'/../uk.json'), true, flags: JSON_THROW_ON_ERROR);
