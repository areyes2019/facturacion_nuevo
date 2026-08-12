<?php

/**
 * Solo se declara lo que este proyecto necesita cambiar; el resto de las opciones las aporta
 * barryvdh/laravel-dompdf con mergeConfigFrom.
 */
return [

    /*
     * Ruta contra la que dompdf resuelve los archivos que el HTML referencie con ruta relativa.
     *
     * Su valor por omisión es base_path('public'), y en producción ese directorio NO EXISTE: el
     * docroot es public_html/, hermano de la aplicación, y el despliegue no sube public/ (ver
     * 018-despliegue-hostinger.md). El paquete hace realpath() sobre la ruta y, al no resolver,
     * lanza "Cannot resolve public path" desde el propio contenedor: falla toda generación de
     * PDF —facturas, cotizaciones y órdenes de compra, tanto al descargarlas como al mandarlas
     * por correo— con un mensaje que no menciona ni el PDF ni el correo.
     *
     * base_path() existe en las dos máquinas y coincide con el chroot que dompdf ya usa por
     * omisión. Hoy la ruta es nominal: ninguna vista de resources/views/pdf/ referencia imagen,
     * hoja de estilo ni fuente externa. Y si alguna llega a necesitarlo, el archivo vivirá en el
     * repositorio, no en un docroot que en producción está en otro lado.
     */
    'public_path' => base_path(),

];
