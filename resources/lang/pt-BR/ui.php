<?php

return json_decode((string) file_get_contents(__DIR__.'/../pt-BR.json'), true, flags: JSON_THROW_ON_ERROR);
