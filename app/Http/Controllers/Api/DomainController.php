<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Credential;
use App\Models\Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DomainController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Logic to handle the request and return a response
        // For example, fetching domains from the database
        $request->validate([
            'key' => 'required|exists:postal_mysql.credentials,key',
        ]);

        try {
            $server = $this->getServerFromCredential($request->key);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ])->setStatusCode(400);
        }

        return response()->json([
            'success' => true,
            'data' => $server->domains,
            'message' => 'Domains retrieved successfully.'
        ])->setStatusCode(200);
    }

    public function showByName(string $name) {
        $domain = \App\Models\Domain::where('name', $name)->first();

        if (!$domain) {
            return response()->json([
                'success' => false,
                'message' => 'Domain not found.'
            ])->setStatusCode(404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'domain' => $domain,
                'dkim_record' => $this->generateDkimRecord($domain, $domain->dkim_identifier_string),
            ],
            'message' => 'Domain retrieved successfully.'
        ])->setStatusCode(200);
    }

    public function create(Request $request): JsonResponse
    {
        // Logic to create a new domain
        $data = $request->validate([
            'name' => 'required|unique:postal_mysql.domains,name',
            'key' => 'required|exists:postal_mysql.credentials,key',
        ], [
            'name.required' => 'The domain name is required.',
            'name.unique' => 'The domain name already exists.'
        ]);

        try {
            $server = $this->getServerFromCredential($data['key']);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ])->setStatusCode(400);
        }

        $domain = $data['name'];

        $verificationMethod = "DNS";
        $verificationToken = Str::random(32);

        // Generate dkim keys using OpenSSL
        $privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($privateKey, $privateKeyString);
        $dkimSelector = Str::random(6);

        $domain = \App\Models\Domain::create([
            'name' => $domain,
            'verification_method' => $verificationMethod,
            'verification_token' => $verificationToken,
            'dkim_private_key' => $privateKeyString,
            'outgoing' => 1,
            'incoming' => 1,
            'owner_type' => 'Server',
            'owner_id' => $server->id,
            'dkim_identifier_string' => $dkimSelector,
            'verified_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'domain' => $domain,
                'dkim_record' => $this->generateDkimRecord($domain, $dkimSelector),
            ],
            'message' => 'Domain created successfully.'
        ])->setStatusCode(201);
    }

    public function verify(Request $request): JsonResponse {
        $request->validate([
            'name' => 'required|exists:postal_mysql.domains,name',
            'spf_record' => 'required'
        ], [
            'name.required' => 'The domain name is required.',
            'name.exists' => 'The domain does not exist.'
        ]);
        $domain = \App\Models\Domain::where('name', $request->name)->first();
        if (!$domain) {
            return response()->json([
                'success' => false,
                'message' => 'Domain not found.'
            ])->setStatusCode(404);
        }

        $dkimRecord = $this->generateDkimRecord($domain, $domain->dkim_identifier_string);

        $spfExists = $this->verifySpf($domain->name, $request->spf_record);
        $dkimRecordExists = $this->verifyDkim($dkimRecord);

        $domain->update([
            'verified_at' => now(),
            'dns_checked_at' => now(),
            'spf_status' => $spfExists ? 'OK' : 'Missing',
            'spf_error' => $spfExists ? null : 'No SPF record found or incorrect SPF record.',
            'dkim_status' => $dkimRecordExists ? 'OK' : 'Missing',
            'dkim_error' => $dkimRecordExists ? null : 'No TXT record found for DKIM or incorrect DKIM record for '. $dkimRecord['name'],
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'spf_status' => $domain->spf_status,
                'dkim_status' => $domain->dkim_status,
                'identify_status' => $domain->verified_at ? 'OK' : 'Missing',
                'dkim_record' => $dkimRecord,
            ],
            'message' => 'Domain verification status updated successfully.'
        ])->setStatusCode(200);
    }

    public function destroy(string $name): JsonResponse
    {
        $domain = \App\Models\Domain::where('name', $name)->first();

        if (!$domain) {
            return response()->json([
                'success' => false,
                'message' => 'Domain not found.'
            ])->setStatusCode(404);
        }

        $domain->delete();

        return response()->json([
            'success' => true,
            'message' => 'Domain deleted successfully.'
        ])->setStatusCode(200);
    }

    private function verifySpf(string $domain, string $expected): bool
    {
        $records = dns_get_record($domain, DNS_TXT);

        foreach ($records as $record) {
            if (isset($record['txt']) && $record['txt'] === $expected) {
                return true;
            }
        }

        return false;
    }

    private function verifyDkim(array $dkim): bool
    {
        $records = dns_get_record($dkim['name'], DNS_TXT);

        foreach ($records as $record) {
            if (isset($record['txt']) && stripos($record['txt'], $dkim['value']) !== false) {
                return true;
            }
        }

        return false;
    }

//    private function generateSpfRecord(string $postalDomain): string
//    {
//        return "v=spf1 a mx include:spf." . $postalDomain . " ~all";
//    }

    private function generateDkimRecord(Domain $domain, string $selector): array
    {
        $dkimPublicKey = openssl_pkey_get_details(openssl_pkey_get_private($domain->dkim_private_key))['key'];
        // strip first and last lines from the public key
        $dkimPublicKey = str_replace(["-----BEGIN PUBLIC KEY-----", "-----END PUBLIC KEY-----", "\n"], "", $dkimPublicKey);
        $dkimRecordValue = "v=DKIM1; t=s; h=sha256; p=" . $dkimPublicKey .";";

        return [
            'name' => "postal-" . $selector . "._domainkey." . $domain->name,
            'value' => $dkimRecordValue,
        ];
    }

    protected function getServerFromCredential(string $key): ?\App\Models\Server
    {
        $credential = Credential::whereRaw('BINARY `key` = ?', [$key])
            ->where('hold', 0)
            ->first();

        if (!$credential) {
            throw new \Exception("Invalid or expired credential key.");
        }

        if (!$credential->server) {
            throw new \Exception("Server not found for the provided key.");
        }

        return $credential->server;
    }
}
