<?php
/**
 * Provider-agnostic pre-qualification for the 350-mi Houston footprint.
 *
 * Each footprint provider (Frontier today, Cox next) is an adapter with the same
 * contract: given a normalized address, return a result array. The page never
 * touches a provider directly — it asks prequal_check_many() which persists the
 * lead (tagged with provider + client_id) and runs every requested provider.
 *
 * Add a provider in two steps: (1) list it in prequal_providers(), (2) write its
 * adapter fn (Cox's API docs are pending — prequal_cox() is a safe placeholder).
 */

/** Registry: provider display name => adapter callable. Frontier is live; Cox is pending. */
function prequal_providers(): array
{
    return [
        'Frontier' => 'prequal_frontier',
        'Cox'      => 'prequal_cox',
    ];
}

/** Dispatch one provider's adapter. */
function prequal_dispatch(string $provider, array $address, string $pon): array
{
    $map = prequal_providers();
    if (!isset($map[$provider]) || !function_exists($map[$provider])) {
        return [
            'provider' => $provider, 'pon' => $pon,
            'available' => null, 'status' => 'PREQUAL_ERROR',
            'raw_response' => null,
            'message' => 'Provider "' . $provider . '" is not configured yet.',
            'success' => false,
        ];
    }
    return $map[$provider]($address, $pon);
}

/**
 * Frontier availability via the confirmed ASR SOAP envelope (TEST env).
 * Wraps the previously inlined flow; returns a normalized result.
 */
function prequal_frontier(array $address, string $pon): array
{
    $logDir = is_writable('/var/log/frontier')
        ? '/var/log/frontier'
        : sys_get_temp_dir() . '/frontier-qualify';
    require_once __DIR__ . '/../frontier-asr-v10/src/Logger.php';
    require_once __DIR__ . '/../frontier-asr-v10/src/FrontierASRClient.php';
    try {
        $client = new FrontierASRClient([
            'environment' => 'TEST',
            'ccna'        => 'BMR',
            'source_ip'   => '5.78.87.79',
        ], new Logger($logDir));
        $res = $client->sendPreOrder([
            'address_line1' => $address['address'],
            'city'          => $address['city'],
            'state'         => $address['state'],
            'zip'           => $address['zip'],
            'pon'           => $pon,
        ]);
        $available = $res['parsed']['available'] ?? null;
        $fault     = $res['parsed']['fault_code'] ?? '';
        $faultMsg  = $res['parsed']['fault_string'] ?? '';
        $status    = $available === true ? 'PREQUAL_AVAILABLE'
                   : ($available === false ? 'PREQUAL_UNAVAILABLE' : 'PREQUAL_CHECKING');
        return [
            'provider'     => 'Frontier',
            'pon'          => $pon,
            'available'    => $available,
            'status'       => $status,
            'raw_response' => $res['response'] ?? null,
            'message'      => ($res['success'] && $available !== null)
                ? null
                : (trim("$fault $faultMsg") ?: 'Frontier is confirming your address with our team.'),
            'success'      => (bool)($res['success'] ?? false),
        ];
    } catch (Throwable $e) {
        return [
            'provider' => 'Frontier', 'pon' => $pon, 'available' => null,
            'status' => 'PREQUAL_CHECKING', 'raw_response' => null,
            'message' => 'Frontier check error: ' . $e->getMessage(), 'success' => false,
        ];
    }
}

/**
 * Cox availability — PLACEHOLDER until Cox API docs land (MC task 1137, ~Sep 19).
 * When the docs arrive, implement the CoxClient call here; the page + storage
 * already handle it (provider column + dual-check).
 */
function prequal_cox(array $address, string $pon): array
{
    return [
        'provider'     => 'Cox',
        'pon'          => $pon,
        'available'    => null,
        'status'       => 'COX_PENDING',
        'raw_response' => null,
        'message'      => 'Cox availability check is not configured yet (Cox API docs pending).',
        'success'      => false,
    ];
}

/**
 * Run an address against one or more providers: persist a lead per provider
 * (tagged provider + client_id), call the adapter, then update the row. Returns
 * provider-keyed results. DB is best-effort — never throws for the visitor.
 */
function prequal_check_many(PDO $pdo, array $address, array $providers, ?int $clientId, string $source = 'fiber.bluemogul.us'): array
{
    $out = [];
    foreach ($providers as $provider) {
        $pon = 'PREQ-' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO frontier_orders
                    (pon, type, provider, address_line1, city, state, zip,
                     contact_name, contact_phone, contact_email,
                     client_id, status, remarks, created_at, updated_at)
                 VALUES (?, 'PRE-ORDER', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PREQUAL_CHECKING', ?, NOW(), NOW())"
            );
            $stmt->execute([
                $pon, $provider, $address['address'], $address['city'], $address['state'], $address['zip'],
                $address['name'] ?: 'Web Inquiry', $address['phone'], $address['email'],
                $clientId, $source,
            ]);
        } catch (Throwable $e) {
            error_log('[prequal] lead INSERT failed for ' . $provider . ': ' . $e->getMessage());
            // DB down — still attempt the provider; page notes the reference.
        }

        $res = prequal_dispatch($provider, $address, $pon);

        try {
            $pdo->prepare(
                "UPDATE frontier_orders SET status = ?, remarks = ?, raw_response = ?, updated_at = NOW() WHERE pon = ?"
            )->execute([
                $res['status'],
                $res['message'] ?: null,
                $res['raw_response'] ? substr($res['raw_response'], 0, 2000) : null,
                $pon,
            ]);
        } catch (Throwable $e) {
            // best-effort
        }

        $out[$provider] = $res;
    }
    return $out;
}