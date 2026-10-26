<?php

it('boots the home page', function () {
    $this->get('/')->assertSuccessful();
});
