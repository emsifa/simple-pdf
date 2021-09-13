<?php

use Emsifa\SimplePdf\SimplePdf;

require __DIR__."/../vendor/autoload.php";

if (php_sapi_name() !== 'cli') {
    exit("For CLI only.");
}

$pdf = new SimplePdf();
$pdf->addPage();
$pdf->setFont('Arial', SimplePdf::STYLE_BOLD, 16);
$pdf->cell(40, 10, 'Hello World!');
$pdf->save('01_hello-world.pdf');