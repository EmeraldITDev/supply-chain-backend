<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AIChatController extends Controller
{
    /** Verified React Router paths only — never invent paths outside this list. */
    private const APPROVED_PATHS = [
        '/dashboard',
        '/procurement',
        '/supply-chain',
        '/executive',
        '/chairman',
        '/new-mrf',
        '/new-srf',
        '/department',
        '/vendors',
        '/logistics',
        '/warehouse',
        '/reports',
        '/reports/procurement',
        '/trips',
        '/trip-request',
        '/vendor-portal',
        '/settings',
        '/accounts-payable',
        '/accounts-receivable',
        '/budget',
    ];

    public function chat(Request $request): JsonResponse|StreamedResponse
    {
        $request->validate([
            'message' => 'required|string|max:2000',
            'history' => 'nullable|array|max:20',
            'history.*.role' => 'required|in:user,assistant',
            'history.*.content' => 'required|string|max:4000',
        ]);

        $user = $request->user();
        $role = $user->scmRole() ?? $user->supply_chain_role ?? 'employee';
        $department = (string) ($user->department ?? '');
        $systemPrompt = $this->buildSystemPrompt($user, $role, $department);

        // Build Gemini contents from prior turns + current message.
        $contents = [];
        foreach ($request->history ?? [] as $msg) {
            $roleName = $msg['role'] ?? '';
            $text = trim((string) ($msg['content'] ?? ''));
            if (! in_array($roleName, ['user', 'assistant'], true) || $text === '') {
                continue;
            }
            $contents[] = [
                'role' => $roleName === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $text]],
            ];
        }
        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $request->message]],
        ];

        // Gemini requires the first turn to be user.
        while (! empty($contents) && ($contents[0]['role'] ?? '') === 'model') {
            array_shift($contents);
        }

        // Merge consecutive same-role turns (Gemini rejects non-alternating roles).
        $contents = $this->mergeConsecutiveRoles($contents);

        if (empty($contents)) {
            return response()->json([
                'success' => false,
                'error' => 'No message content to send.',
                'code' => 'EMPTY_CONTENTS',
            ], 422);
        }

        $apiKey = config('services.gemini.key');
        if (! is_string($apiKey) || trim($apiKey) === '') {
            Log::error('Gemini API key is not configured on the backend (GEMINI_API_KEY)');

            return response()->json([
                'success' => false,
                'error' => 'AI is not configured on the server. Set GEMINI_API_KEY on the Render backend (not Vercel) and redeploy.',
                'code' => 'GEMINI_KEY_MISSING',
            ], 503);
        }

        $model = $this->resolveGeminiModel(
            (string) config('services.gemini.model', 'gemini-2.5-flash')
        );

        return response()->stream(function () use ($contents, $systemPrompt, $apiKey, $model, $request) {
            // Disable PHP output buffering so tokens reach the client immediately.
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            try {
                $response = Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => trim($apiKey),
                    'Accept' => 'text/event-stream',
                ])->timeout(60)->withOptions(['stream' => true])->post(
                    'https://generativelanguage.googleapis.com/v1beta/models/'
                        .$model
                        .':streamGenerateContent?alt=sse',
                    [
                        'system_instruction' => [
                            'parts' => [['text' => $systemPrompt]],
                        ],
                        'contents' => $contents,
                        'generationConfig' => [
                            'maxOutputTokens' => 1024,
                            'temperature' => 0.7,
                        ],
                    ]
                );

                if ($response->failed()) {
                    $body = $response->body();
                    $geminiMessage = data_get(json_decode($body, true), 'error.message');
                    Log::error('Gemini streaming API error', [
                        'status' => $response->status(),
                        'model' => $model,
                        'history_turns' => count($request->history ?? []),
                        'body' => $body,
                    ]);

                    echo 'data: '.json_encode([
                        'error' => 'AI service temporarily unavailable.',
                        'code' => 'GEMINI_API_ERROR',
                        'gemini_status' => $response->status(),
                        'gemini_message' => is_string($geminiMessage) ? $geminiMessage : null,
                    ])."\n\n";
                    flush();

                    return;
                }

                $body = $response->toPsrResponse()->getBody();
                while (! $body->eof()) {
                    $chunk = $body->read(1024);
                    if ($chunk === '' || $chunk === false) {
                        break;
                    }
                    echo $chunk;
                    flush();
                }
            } catch (\Throwable $e) {
                Log::error('AI chat stream error', ['error' => $e->getMessage()]);
                echo 'data: '.json_encode([
                    'error' => 'An error occurred. Please try again.',
                    'code' => 'GEMINI_EXCEPTION',
                ])."\n\n";
                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @param  list<array{role: string, parts: list<array{text: string}>}>  $contents
     * @return list<array{role: string, parts: list<array{text: string}>}>
     */
    private function mergeConsecutiveRoles(array $contents): array
    {
        $merged = [];
        foreach ($contents as $turn) {
            $role = $turn['role'];
            $text = $turn['parts'][0]['text'] ?? '';
            if ($text === '') {
                continue;
            }
            $last = end($merged);
            if ($last !== false && ($last['role'] ?? null) === $role) {
                $merged[count($merged) - 1]['parts'][0]['text'] =
                    trim($last['parts'][0]['text']."\n\n".$text);
            } else {
                $merged[] = [
                    'role' => $role,
                    'parts' => [['text' => $text]],
                ];
            }
        }

        return $merged;
    }

    private function resolveGeminiModel(string $model): string
    {
        $model = trim($model);
        if ($model === '') {
            return 'gemini-2.5-flash';
        }

        $retired = [
            'gemini-2.0-flash',
            'gemini-2.0-flash-001',
            'gemini-2.0-flash-lite',
            'gemini-2.0-flash-lite-001',
            'gemini-1.5-flash',
            'gemini-1.5-flash-latest',
            'gemini-1.5-pro',
            'gemini-1.5-pro-latest',
        ];

        if (in_array($model, $retired, true)) {
            Log::warning('Retired Gemini model requested; remapping', [
                'from' => $model,
                'to' => 'gemini-2.5-flash',
            ]);

            return 'gemini-2.5-flash';
        }

        return $model;
    }

    private function buildSystemPrompt($user, string $role, string $department): string
    {
        $roleContext = $this->getRoleContext($role);
        $navigationMap = $this->getNavigationMap($role);
        $employeeId = $user->employee_id ?? 'N/A';
        $name = $user->name ?? 'User';
        $approvedPaths = implode("\n", array_map(
            fn (string $path) => "- {$path}",
            self::APPROVED_PATHS
        ));

        return <<<PROMPT
You are the AI assistant for Emerald Industrial Co. CFZE's Supply Chain Management (SCM) platform. You are embedded directly in the platform and help users navigate it, understand their data, and complete procurement workflows.

## Current User
- Name: {$name}
- Role: {$role}
- Department: {$department}
- Employee ID: {$employeeId}

## Your Role Context
{$roleContext}

## Platform Navigation
The platform has these main sections accessible to this user (path in parentheses):
{$navigationMap}

## Approved Navigation Paths (exact strings only)
{$approvedPaths}

## Platform Glossary
- MRF: Material Request Form — used to request procurement of goods
- SRF: Service Request Form — used to request procurement of services
- MRN: Material Request Note — initial material request
- RFQ: Request for Quotation — sent to vendors to collect pricing
- PO: Purchase Order — formal order sent to a selected vendor
- GRN: Goods Received Note — confirms delivery of goods
- JCC: Job Completion Certificate — confirms completion of a service
- SCD: Supply Chain Director — senior approver for procurement
- PFI: Proforma Invoice — advance invoice from vendor before delivery
- Vendor Registration: process for new suppliers to join the platform

## Workflow Overview
1. Employee creates MRF or SRF
2. SCD or Executive approves (parallel — first to approve wins)
3. Procurement reviews and issues RFQ to vendors
4. Vendors submit quotations
5. Procurement selects vendor and creates price comparison
6. SCD approves vendor selection
7. Procurement generates PO
8. SCD signs PO
9. Vendor delivers — GRN uploaded
10. Finance processes payment

## Logistics Workflow
1. Employee submits Trip Request
2. Logistics Manager reviews and forwards to SCD
3. SCD approves
4. Logistics Manager converts to Logistics Request
5. Vehicle/vendor assigned
6. Journey created and tracked
7. JCC generated on completion

## Response Guidelines
- Be concise and direct — users are busy professionals
- When a user asks how to do something, give numbered steps
- When a user asks where something is, tell them the exact menu item or page name and the path
- When referring to navigation, use the exact names shown in the sidebar
- Remember prior turns in this conversation and answer follow-ups in that context
- If a user asks about their specific data (their MRFs, their approvals), let them know you can see their role context but they should check the relevant dashboard section for live data
- Never make up data or invent MRF numbers, PO numbers, or vendor names
- If you cannot answer something, say so clearly and suggest where they can find the answer
- Keep responses under 200 words unless a detailed explanation is genuinely needed
- Use plain language — not overly technical

## Navigation Actions
When your response involves navigating somewhere, end your message with a JSON action block on its own line:
ACTION:{"type":"navigate","path":"/procurement"}

Only use these exact paths — do not invent paths that are not in the approved list above.
Never use /supply-chain-dashboard, /procurement-dashboard, /mrf-list, /po-list, or any path with a -dashboard suffix.
There is no /logistics/fleet route — fleet lives under Logistics at /logistics.
Only include an ACTION block when navigation would genuinely help the user complete their task.

## Critical Navigation Rule
You must ONLY use navigation paths from the approved list above.
NEVER invent a path. NEVER use /supply-chain-dashboard, /procurement-dashboard, /mrf-list, /po-list, or any other path not explicitly listed.
If you are not certain a path exists, do not include an ACTION block.
It is better to describe where to go than to send the user to a 404 page.

When a user asks to go somewhere, confirm you know the exact path before including an ACTION block.
If the destination maps to a tab within a page rather than its own route (for example MRFs, RFQs, and Purchase Orders are tabs within /procurement), tell the user to go to that page and then click the relevant tab — do not guess a sub-route.
Examples:
- Purchase orders / RFQs / MRFs → /procurement (then the matching tab)
- Sign a PO / SCD approvals → /supply-chain
- Executive approvals → /executive
PROMPT;
    }

    private function getRoleContext(string $role): string
    {
        return match ($role) {
            'procurement_manager', 'procurement' =>
                'This user manages procurement. They create POs, manage RFQs, review vendor quotations, and oversee the full procurement lifecycle.',
            'supply_chain_director', 'supply_chain' =>
                'This user is the Supply Chain Director. They approve MRFs, sign POs, approve vendor selections, and oversee logistics requests.',
            'executive', 'director' =>
                'This user is an Executive. They approve MRFs and SRFs, can create their own requests, and have visibility across all procurement activity.',
            'logistics_manager', 'logistics', 'logistics_officer' =>
                'This user manages logistics. They review trip requests, assign vehicles and drivers, manage the fleet, and oversee journey management.',
            'chairman' =>
                'This user is the Chairman. They approve high-value executive-originated MRFs before they proceed to procurement.',
            'vendor' =>
                'This user is a vendor. They can view RFQs sent to them, submit quotations, upload invoices, and track their submissions.',
            'finance', 'finance_officer' =>
                'This user works in Finance. They process payments, review GRNs/JCCs, and track accounts payable related to procurement.',
            default =>
                'This user is a staff member. They can create MRFs and SRFs, submit trip requests, and track the status of their requests.',
        };
    }

    private function getNavigationMap(string $role): string
    {
        $common = implode("\n", [
            '- Dashboard (/dashboard): overview of activity and pending items',
            '- Trip Request (/trip-request): submit a request for travel',
            '- All Trips (/trips): view travel records',
        ]);

        $procurement = implode("\n", [
            '- Procurement (/procurement): MRFs, RFQs, Service Requests, Purchase Orders, and Logistics POs (use in-page tabs — no separate routes)',
            '- Vendors (/vendors): vendor directory and registrations',
            '- Logistics (/logistics): trip management, fleet, journey tracking',
            '- Warehouse & Inventory (/warehouse): stock management',
            '- Reports (/reports): analytics overview',
            '- Procurement Reports (/reports/procurement): procurement analytics',
        ]);

        return match ($role) {
            'procurement_manager', 'procurement' => $common."\n".$procurement,
            'supply_chain_director', 'supply_chain' => $common."\n- Supply Chain Dashboard (/supply-chain): approvals queue and PO signing\n".$procurement,
            'executive', 'director' => $common."\n- Executive Dashboard (/executive): approvals and my requests\n- New MRF (/new-mrf): create a material request\n- New SRF (/new-srf): create a service request\n".$procurement,
            'logistics_manager', 'logistics', 'logistics_officer' => $common."\n- Logistics (/logistics): overview, trips, journeys, fleet, GPS, and materials (in-page sections — not separate routes)\n- Vendors (/vendors): vendor directory",
            'vendor' => '- Vendor Portal (/vendor-portal): your quotations, invoices, and trip assignments',
            'chairman' => $common."\n- Chairman Dashboard (/chairman): high-value MRF and payment approvals",
            'finance', 'finance_officer' => $common."\n- Accounts Payable (/accounts-payable): AP visibility\n".$procurement,
            default => $common."\n- My Requests (/department): your submitted MRFs and SRFs\n- New MRF (/new-mrf): create a material request\n- New SRF (/new-srf): create a service request\n- Annual Planning (/department): department budget planning (Annual Planning tab)",
        };
    }
}
