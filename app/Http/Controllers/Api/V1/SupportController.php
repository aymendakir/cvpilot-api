<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Admin\ListContactMessagesRequest;
use App\Http\Requests\Admin\UpdateContactMessageRequest;
use App\Http\Requests\Support\ContactMessageRequest;
use App\Models\SupportMessage;

class SupportController
{
    public function store(ContactMessageRequest $request)
    {
        $data = $request->validated();
        unset($data['website']);
        $message = SupportMessage::create($data);

        return response()->json(['message' => 'Your request has been received. Keep this reference for follow-up.', 'reference' => 'CVP-'.$message->id], 201);
    }

    public function index(ListContactMessagesRequest $request)
    {
        $data = $request->validated();

        return SupportMessage::when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest()->paginate($request->perPage(20));
    }

    public function update(UpdateContactMessageRequest $request, SupportMessage $message)
    {
        $message->update($request->validated());
        AuthController::audit($request, 'support_message_'.$message->id.'_updated', $request->user()->id);

        return $message;
    }
}
