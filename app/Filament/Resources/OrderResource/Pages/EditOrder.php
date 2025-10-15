<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Models\ProductBranch;
use Filament\Resources\Pages\EditRecord;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    /** Toggle this off once verified */
    protected bool $debug = true;

    /** Normalize state from DB for compare/persist */
    protected function buildState(): array
    {
        $this->record->refresh();
        $this->record->loadMissing('items');

        $items = $this->record->items
            ->mapToGroups(fn ($i) => [(int) $i->product_id => (int) $i->qty])
            ->map(fn ($g) => (int) $g->sum())
            ->all();
        ksort($items);

        // IMPORTANT: normalize type to lowercase so "Order"/"order" works
        $type = strtolower((string) ($this->record->type ?? 'order'));

        return [
            'branch_id' => (int) $this->record->branch_id,
            'type'      => $type,           // 'order' | 'proforma'
            'items'     => $items,          // [product_id => qty]
        ];
    }

    /** First run: capture PRE-SAVE snapshot so old != new */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (empty($this->record->stock_state)) {
            $preItems = $this->record->items()
                ->get(['product_id', 'qty'])
                ->mapToGroups(fn ($i) => [(int) $i->product_id => (int) $i->qty])
                ->map(fn ($g) => (int) $g->sum())
                ->all();
            ksort($preItems);

            $this->data['__pre_state__'] = [
                'branch_id' => (int) $this->record->branch_id,
                'type'      => strtolower((string) ($this->record->type ?? 'order')),
                'items'     => $preItems,
            ];
        }

        return $data;
    }

    protected function afterSave(): void
    {
        // NEW (true DB state after Filament saved relations)
        $new = $this->buildState();

        // OLD = last reconciled, else pre-save snapshot (first run), else new
        $old = $this->record->stock_state ?? ($this->data['__pre_state__'] ?? $new);

        if ($old === $new) {
            if (empty($this->record->stock_state)) {
                $this->record->forceFill(['stock_state' => $new])->saveQuietly();
            }
            if ($this->debug) {
                Notification::make()->title('Stock: no change')->body('State unchanged.')->success()->send();
            }
            return;
        }

        $oldBranch = (int) $old['branch_id'];
        $newBranch = (int) $new['branch_id'];
        $oldType   = (string) $old['type'];
        $newType   = (string) $new['type'];
        $oldItems  = (array)  $old['items'];
        $newItems  = (array)  $new['items'];

        $moves = []; // [branch_id, product_id, delta]; delta>0 restock, delta<0 deduct

        if ($oldType === 'order' && $newType === 'order') {
            if ($oldBranch === $newBranch) {
                // Same branch: delta = old - new (increase qty ⇒ negative ⇒ deduct)
                $pids = array_unique(array_merge(array_keys($oldItems), array_keys($newItems)));
                foreach ($pids as $pid) {
                    $delta = (int)($oldItems[$pid] ?? 0) - (int)($newItems[$pid] ?? 0);
                    if ($delta !== 0) {
                        $moves[] = [$newBranch, (int)$pid, $delta];
                    }
                }
            } else {
                // Branch changed: restock OLD to old branch, deduct NEW from new branch
                foreach ($oldItems as $pid => $q) if ($q > 0) $moves[] = [$oldBranch, (int)$pid, +$q];
                foreach ($newItems as $pid => $q) if ($q > 0) $moves[] = [$newBranch, (int)$pid, -$q];
            }
        } elseif ($oldType === 'proforma' && $newType === 'order') {
            foreach ($newItems as $pid => $q) if ($q > 0) $moves[] = [$newBranch, (int)$pid, -$q];
        } elseif ($oldType === 'order' && $newType === 'proforma') {
            foreach ($oldItems as $pid => $q) if ($q > 0) $moves[] = [$oldBranch, (int)$pid, +$q];
        }

        if ($this->debug) {
            // Human-readable debug
            $lines = [];
            foreach ($moves as [$b, $p, $d]) {
                $lines[] = "branch:$b product:$p delta:$d";
            }
            $msg = "OLD: ".json_encode($old)." \nNEW: ".json_encode($new)." \nMOVES:\n".($lines ? implode("\n", $lines) : '[none]');
            logger()->debug('[EditOrder] Stock reconcile', ['old' => $old, 'new' => $new, 'moves' => $moves]);
            Notification::make()
                ->title('Stock reconcile (debug)')
                ->body(strlen($msg) > 450 ? substr($msg, 0, 450).'…' : $msg)
                ->success()
                ->send();
        }

        if ($moves) {
            DB::transaction(function () use ($moves) {
                foreach ($moves as [$branchId, $productId, $delta]) {
                    $row = ProductBranch::query()
                        ->where('branch_id', $branchId)
                        ->where('product_id', $productId)
                        ->lockForUpdate()
                        ->first();

                    if (!$row) {
                        $row = new ProductBranch();
                        $row->branch_id  = $branchId;
                        $row->product_id = $productId;
                        $row->stock      = 0;
                    }

                    $current = (int) $row->stock;

                    // if stock is 0, ignore both increase/decrease moves
                    if ($current === 0) {
                        continue;
                    }

                    // apply delta only if there’s stock
                    $row->stock = max(0, $current + (int) $delta);
                    $row->save();
                }
            });

            Notification::make()
                ->title('Stock updated')
                ->body('Branch/product stock levels were reconciled successfully.')
                ->success()
                ->send();
        }

        // Persist NEW as last reconciled for idempotency
        $this->record->forceFill(['stock_state' => $new])->saveQuietly();
    }
}
