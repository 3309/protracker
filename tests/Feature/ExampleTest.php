<?php

test('the home page sends visitors to the dashboard', function () {
    $response = $this->get('/');

    $response->assertRedirect('/dashboard');
});
