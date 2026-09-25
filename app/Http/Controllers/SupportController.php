<?php
namespace App\Http\Controllers;

use App\Models\SupportMessage;
use Illuminate\Http\Request;

class SupportController {
    public function store(Request $request) {
        $data = $request->validate([
            'name'=>'required|string|max:120', 'email'=>'required|email|max:254',
            'topic'=>'required|in:account,technical,privacy,feedback', 'message'=>'required|string|min:20|max:5000',
            'website'=>'nullable|string|max:0',
        ]);
        unset($data['website']);
        $message = SupportMessage::create($data);
        return response()->json(['message'=>'Your request has been received. Keep this reference for follow-up.', 'reference'=>'CVP-'.$message->id], 201);
    }
    public function index(Request $request) {
        $data = $request->validate(['status'=>'nullable|in:new,read,closed', 'page'=>'nullable|integer|min:1']);
        return SupportMessage::when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest()->paginate(20);
    }
    public function update(Request $request, SupportMessage $message) {
        $message->update($request->validate(['status'=>'required|in:new,read,closed']));
        AuthController::audit($request, 'support_message_'.$message->id.'_updated', $request->user()->id);
        return $message;
    }
}
