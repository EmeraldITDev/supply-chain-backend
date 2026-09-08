<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AIChatController extends Controller
{
    public function chat(Request $request): JsonResponse
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

        $messages = [];
        foreach ($request->history ?? [] as $msg) {
            $messages[] = [
                'role' => $msg['role'],
                'content' => $msg['content'],
            ];
        }
        $messages[] = [
            'role' => 'user',
            'content' => $request->message,
        ];

        // Gemini contents should start with a user turn.
        while (! empty($messages) && ($messages[0]['role'] ?? '') === 'assistant') {
            array_shift($messages);
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

        try {
            // Prefer x-goog-api-key header; do not put the key in the URL.
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'x-goog-api-key' => trim($apiKey),
            ])->timeout(30)->post(
                'https://generativelanguage.googleapis.com/v1beta/models/'
                    .$model
                    .':generateContent',
                [
                    'system_instruction' => [
                        'parts' => [['text' => $systemPrompt]],
                    ],
                    'contents' => array_map(fn ($msg) => [
                        'role' => $msg['role'] === 'assistant' ? 'model' : 'user',
                        'parts' => [['text' => $msg['content']]],
                    ], $messages),
                    'generationConfig' => [
                        'maxOutputTokens' => 1024,
                        'temperature' => 0.7,
                    ],
                ]
            );

            if ($response->failed()) {
                $geminiMessage = data_get($response->json(), 'error.message');
                Log::error('Gemini API error', [
                    'status' => $response->status(),
                    'model' => $model,
                    'body' => $response->body(),
                ]);

                $clientError = 'AI service temporarily unavailable.';
                if ($response->status() === 404) {
                    $clientError = 'Gemini model is unavailable. On Render set GEMINI_MODEL=gemini-2.5-flash (gemini-2.0-flash is shut down), then redeploy.';
                } elseif ($response->status() === 400 || $response->status() === 401 || $response->status() === 403) {
                    $clientError = 'Gemini rejected the API key or request. Check GEMINI_API_KEY on Render.';
                }

                return response()->json([
                    'success' => false,
                    'error' => $clientError,
                    'code' => 'GEMINI_API_ERROR',
                    'gemini_status' => $response->status(),
                    'gemini_message' => is_string($geminiMessage) ? $geminiMessage : null,
                ], 503);
            }

            $data = $response->json();
            $reply = $data['candidates'][0]['content']['parts'][0]['text']
                ?? 'I could not generate a response. Please try again.';

            return response()->json([
                'success' => true,
                'data' => [
                    'reply' => $reply,
                    'model' => $data['modelVersion'] ?? $model,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('AI chat error', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => 'An error occurred. Please try again.',
                'code' => 'GEMINI_EXCEPTION',
            ], 500);
        }
    }

    /**
     * Map shut-down / legacy model ids to a current Gemini Flash model.
     */
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
The platform has these main sections accessible to this user:
{$navigationMap}

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
- When a user asks where something is, tell them the exact menu item or page name
- When referring to navigation, use the exact names shown in the sidebar
- If a user asks about their specific data (their MRFs, their approvals), let them know you can see their role context but they should check the relevant dashboard section for live data
- Never make up data or invent MRF numbers, PO numbers, or vendor names
- If you cannot answer something, say so clearly and suggest where they can find the answer
- Keep responses under 200 words unless a detailed explanation is genuinely needed
- Use plain language — not overly technical

## Navigation Actions
When your response involves navigating somewhere, end your message with a JSON action block on its own line:
ACTION:{"type":"navigate","path":"/procurement"}
or
ACTION:{"type":"navigate","path":"/new-mrf"}

Only include an ACTION block when navigation would genuinely help the user complete their task.
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
        $common = "- Dashboard: overview of activity and pending items\n- Trip Request: submit a request for travel\n- All Trips: view travel records";

        $procurement = "- Procurement > Material Requests (MRN): initial material requests\n- Procurement > MRF Official: formal material request forms\n- Procurement > All MRFs: all material requests\n- Procurement > RFQ Management: manage requests for quotation\n- Procurement > Service Requests: service request forms\n- Procurement > Purchase Orders: manage and generate POs\n- Procurement > Logistics POs: purchase orders for logistics\n- Vendors: vendor directory and registrations\n- Logistics: trip management, fleet, journey tracking\n- Warehouse & Inventory: stock management\n- Reports: procurement analytics and reporting";

        return match ($role) {
            'procurement_manager', 'procurement' => $common."\n".$procurement,
            'supply_chain_director', 'supply_chain' => $common."\n- Supply Chain Dashboard: approvals queue and PO signing\n".$procurement,
            'executive', 'director' => $common."\n- Executive Dashboard: approvals and my requests\n- New MRF: create a material request\n- New SRF: create a service request\n".$procurement,
            'logistics_manager', 'logistics', 'logistics_officer' => $common."\n- Logistics > Overview: logistics dashboard\n- Logistics > Trips: all trip records\n- Logistics > Journeys: active journey tracking\n- Logistics > Fleet: vehicle management\n- Logistics > GPS: live tracking\n- Logistics > Materials: material movements",
            'vendor' => "- Vendor Portal: your quotations, invoices, and trip assignments",
            'chairman' => $common."\n- Chairman Dashboard: high-value MRF and payment approvals",
            'finance', 'finance_officer' => $common."\n- Finance Dashboard: payment processing and AP visibility\n".$procurement,
            default => $common."\n- My Requests: your submitted MRFs and SRFs\n- New MRF: create a material request\n- New SRF: create a service request\n- Annual Planning: department budget planning",
        };
    }
}
