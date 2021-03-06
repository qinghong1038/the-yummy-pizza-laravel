<?php

namespace App\Repositories;

use App\Models\DeliverablePostCode;
use App\Models\Menu;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PriceOption;
use Cart;
use Darryldecode\Cart\CartCondition;
use Darryldecode\Cart\Exceptions\InvalidConditionException;
use Darryldecode\Cart\Exceptions\UnknownModelException;

/**
 * Class OrderRepository
 * @package App\Repositories
 * @version May 4, 2020, 9:44 am UTC
 */
class OrderRepository extends BaseRepository
{
    /**
     * @var array
     */
    protected $fieldSearchable = [
        'order_number',
        'user_id',
        'status',
        'grand_total',
        'item_count',
        'payment_status',
        'payment_method',
        'first_name',
        'last_name',
        'address',
        'city',
        'country',
        'post_code',
        'phone_number',
        'notes'
    ];

    /**
     * Return searchable fields
     *
     * @return array
     */
    public function getFieldsSearchable ()
    {
        return $this->fieldSearchable;
    }

    /**
     * Configure the Model
     **/
    public function model ()
    {
        return Order::class;
    }

    public function storeOrderDetails ($params, $userId = null)
    {
        foreach ($params['carts'] as $item) {
            try {
                Cart::add($item['id'], $item['title'], $item['price'], $item['quantity'], ["priceOptionId" => $item['price_option_id']])
                    ->associate(Menu::class);
            } catch (UnknownModelException $e) {
            }
        }

        try {
            $vatCondition = new CartCondition([
                'name'   => 'VAT 10%',
                'type'   => 'tax',
                'target' => 'subtotal',
                'value'  => '10%',
                'order'  => 1 // TODO: to be justified whether vat or delivery fees should be applied first
            ]);
        } catch (InvalidConditionException $e) {
        }

        $deliverablePostCode = DeliverablePostCode::where('post_code', $params['post_code'])->first();

        try {
            $deliveryFeesCondition = new CartCondition([
                'name'   => 'Delivery Fees',
                'type'   => 'shipping',
                'target' => 'subtotal',
                'value'  => '+' . $deliverablePostCode->delivery_fees,
                'order'  => 2 // TODO: to be justified whether vat or delivery fees should be applied first
            ]);
        } catch (InvalidConditionException $e) {
        }

        Cart::condition($vatCondition);
        Cart::condition($deliveryFeesCondition);

        $order = Order::create([
            'order_number'   => 'TYP' . strtoupper(uniqid()),
            'user_id'        => $userId,
            'status'         => 'pending',
            'grand_total'    => Cart::getSubTotal(),
            'item_count'     => Cart::getTotalQuantity(),
            'payment_status' => $params['payment_status'],
            'payment_method' => $params['payment_method'],
            'first_name'     => $params['first_name'],
            'last_name'      => $params['last_name'],
            'address'        => $params['address'],
            'city'           => $params['city'],
            'country'        => $params['country'],
            'post_code'      => $params['post_code'],
            'phone_number'   => $params['phone_number'],
            'notes'          => $params['notes']
        ]);

        if ($order) {
            $items = Cart::getContent();

            foreach ($items as $item) {

                $menu = Menu::find($item->id);
                $priceOption = PriceOption::find($item->attributes->priceOptionId);

                $orderItem = new OrderItem([
                    'menu_id'         => $menu->id,
                    'quantity'        => $item->quantity,
                    'price'           => $item->getPriceSum(),
                    'price_option_id' => $priceOption->id
                ]);

                $order->items()->save($orderItem);
            }
        }

        Cart::clear();

        return $order;
    }
}
