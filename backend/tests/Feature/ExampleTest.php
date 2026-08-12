<?php

test('example', function () {
    $response = $this->get('/up');

    $response->assertStatus(200);
});
