<?php

namespace App\Http\Controllers\Api\V1\Logistics;

use App\Models\Logistics\VendorCompliance;
use App\Models\Vendor;
use App\Services\Logistics\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class VendorController extends ApiController
{
    public function __construct(private AuditLogger $auditLogger)
    {
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:vendors,email',
            'category' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'compliance' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        }

        $vendor = Vendor::create([
            'vendor_id' => Vendor::generateVendorId(),
            'name' => $request->name,
            'email' => $request->email,
            'category' => $request->category,
            'phone' => $request->phone,
            'address' => $request->address,
            'contact_person' => $request->contact_person,
            'notes' => $request->notes,
            'status' => 'active',
        ]);

        VendorCompliance::updateOrCreate([
            'vendor_id' => $vendor->id,
        ], [
            'status' => 'pending',
            'metadata' => $request->input('compliance', []),
        ]);

        $this->auditLogger->log('vendor_created', $request->user(), 'vendor', (string) $vendor->id, $vendor->toArray(), $request);

        return $this->success([
            'vendor' => $vendor,
        ], 201);
    }

    public function index(Request $request)
    {
        $query = Vendor::query();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return $this->success([
            'vendors' => $query->paginate(20),
        ]);
    }

    public function show(int $id)
    {
        $vendor = Vendor::with('registrations')->find($id);

        if (!$vendor) {
            return $this->error('Vendor not found', 'NOT_FOUND', 404);
        }

        $compliance = VendorCompliance::where('vendor_id', $vendor->id)->first();

        return $this->success([
            'vendor' => $vendor,
            'compliance' => $compliance,
        ]);
    }

    public function update(Request $request, int $id)
    {
        $vendor = Vendor::find($id);

        if (!$vendor) {
            return $this->error('Vendor not found', 'NOT_FOUND', 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'category' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'status' => 'nullable|string|max:50',
            'compliance' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        }

        $vendor->fill($validator->validated());
        $vendor->save();

        if ($request->has('compliance')) {
            VendorCompliance::updateOrCreate([
                'vendor_id' => $vendor->id,
            ], [
                'status' => $request->input('compliance.status', 'pending'),
                'metadata' => $request->input('compliance', []),
                'reviewed_at' => now(),
            ]);
        }

        $this->auditLogger->log('vendor_updated', $request->user(), 'vendor', (string) $vendor->id, $vendor->toArray(), $request);

        return $this->success([
            'vendor' => $vendor,
        ]);
    }

    public function invite(Request $request, int $id)
    {
        $vendor = Vendor::find($id);

        if (!$vendor) {
            return $this->error('Vendor not found', 'NOT_FOUND', 404);
        }

        $vendor->status = 'invited';
        $vendor->save();

        $this->auditLogger->log('vendor_invited', $request->user(), 'vendor', (string) $vendor->id, ['status' => 'invited'], $request);

        return $this->success([
            'vendor' => $vendor,
        ]);
    }
}
