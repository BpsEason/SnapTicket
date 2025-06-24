<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Services\TicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class TicketController extends Controller
{
    protected $ticketService;

    public function __construct(TicketService $ticketService)
    {
        $this->ticketService = $ticketService;
    }

    public function grabTicket(Request $request, Ticket $ticket)
    {
        try {
            $userId = $request->user()->id;
            $orderId = $this->ticketService->grabTicket($ticket->id, $userId);

            return response()->json([
                'message' => '搶票成功，訂單已生成！',
                'order_id' => $orderId,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error("搶票失敗: {$e->getMessage()}");
            return response()->json([
                'message' => '搶票失敗，請稍後重試。',
            ], 500);
        }
    }

    public function getTicketStock(Ticket $ticket)
    {
        $stock = $this->ticketService->getTicketStock($ticket->id);

        return response()->json([
            'ticket_id' => $ticket->id,
            'stock' => $stock,
        ]);
    }
}
