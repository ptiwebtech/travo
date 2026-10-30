<?php
/*
 * Travo override of Fleet-Ops' order label (fleetops::labels/default), Oct 2026.
 * Same content as the original, laid out as full-width rows (section name above the address) so long addresses wrap inside the box
 * (the original used floats with no width, so long lines ran past the border in the PDF).
 * Address parts that are already contained in the street line are not repeated.
 */
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Support\Utils;

$e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

if ($order) {
    $order->load('payload.pickup', 'payload.dropoff', 'payload.entities', 'purchaseRate.serviceQuote.serviceRate');
    $pickup   = $pickup ?? $order->payload->pickup;
    $dropoff  = $dropoff ?? $order->payload->dropoff;
    $entities = $entities ?? ($order->payload['entities'] ?? []);
}
$tn          = Utils::notEmpty($trackingNumber ?? null) ? $trackingNumber : $order->trackingNumber;
$serviceName = ($order && $order instanceof Order) ? data_get($order, 'purchaseRate.serviceQuote.serviceRate.service_name') : null;

/** Address lines for a place: name, street lines, then the remaining parts not already in the street text. */
$lines = function ($place) {
    if (!$place) {
        return [];
    }
    $out    = [];
    $street = trim(implode(', ', array_filter([$place->street1, $place->street2])));
    if (!empty($place->name) && strcasecmp(trim($place->name), $street) !== 0) {
        $out[] = ['bold' => true, 'text' => $place->name];
    }
    if ($street !== '') {
        $out[] = ['bold' => false, 'text' => $street];
    }
    $seen = strtolower($street . ' ' . ($place->name ?? ''));
    $rest = [];
    foreach (['neighborhood', 'district', 'postal_code', 'city', 'province', 'country'] as $k) {
        $v = trim((string) ($place->{$k} ?? ''));
        // skip 2-letter country codes (e.g. "NG") when the address already names the place
        if ($k === 'country' && strlen($v) === 2 && ($street !== '' || $rest)) {
            continue;
        }
        if ($v !== '' && !str_contains($seen, strtolower($v)) && !in_array($v, $rest, true)) {
            $rest[] = $v;
        }
    }
    if ($rest) {
        $out[] = ['bold' => false, 'text' => implode(', ', $rest)];
    }
    if (!empty($place->phone_number)) {
        $out[] = ['bold' => false, 'text' => $place->phone_number];
    }

    return $out;
};
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <title><?= $e($company->name ?? ($order->internal_id ?? $order->public_id)) ?> Label</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #000; }
        .label { width: 420px; border: 2px solid #414141; margin: 0 auto; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        td { vertical-align: top; word-wrap: break-word; overflow-wrap: break-word; }
        .row { border-bottom: 1px solid #414141; }
        .k { font-weight: bold; font-size: 10px; letter-spacing: .5px; color: #414141; margin-bottom: 3px; }
        .v { padding: 10px 12px; font-size: 12px; line-height: 1.35; }
        .v .b { font-weight: bold; }
    </style>
</head>
<body>
<div class="label">
    <table class="row">
        <tr>
            <td width="120" style="width: 120px; height: 110px; padding: 8px; border-right: 1px solid #414141; text-align: center;">
                <img src="data:image/png;base64,<?= $tn->qr_code ?>" style="width: 104px; height: 104px;">
            </td>
            <td style="padding: 12px 12px 8px 12px;">
                <div style="font-size: 15px; font-weight: bold;"><?= $e(strtoupper($company->name ?? '')) ?></div>
                <?php if ($serviceName) { ?><div style="font-size: 12px; margin-top: 4px;"><?= $e(strtoupper($serviceName)) ?></div><?php } ?>
            </td>
        </tr>
    </table>

    <?php foreach ([['PICKUP:', $pickup ?? null], ['DROPOFF:', $dropoff ?? null]] as [$title, $place]) {
        if (!$place) { continue; } ?>
        <table class="row">
            <tr>
                <td class="v">
                    <div class="k"><?= $title ?></div>
                    <?php foreach ($lines($place) as $l) { ?>
                        <div<?= $l['bold'] ? ' class="b"' : '' ?>><?= $e($l['text']) ?></div>
                    <?php } ?>
                </td>
            </tr>
        </table>
    <?php } ?>

    <?php if (!empty($entities) && count($entities)) { ?>
        <table class="row">
            <tr>
                <td class="v">
                    <div class="k">ITEMS:</div>
                    <?php foreach ($entities as $entity) { ?>
                        <div class="b"><?= $e(!empty($entity['name']) ? strtoupper($entity['name']) . ' - ' . ($entity['internal_id'] ?? '') : 'ITEM - ' . ($entity['internal_id'] ?? '')) ?></div>
                    <?php } ?>
                </td>
            </tr>
        </table>
    <?php } ?>

    <table>
        <tr>
            <td style="text-align: center; padding: 14px 8px;">
                <img src="data:image/png;base64,<?= $tn->barcode ?>" style="height: 80px; max-width: 380px;">
                <div style="font-size: 12px; margin-top: 4px;"><?= $e(strtoupper($tn->tracking_number)) ?></div>
            </td>
        </tr>
    </table>
</div>
</body>
</html>
