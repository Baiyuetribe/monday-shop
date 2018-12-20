<?php

namespace App\Http\Controllers\User;

use App\Exceptions\OrderException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Jobs\CancelUnPayOrder;
use App\Models\Address;
use App\Models\Car;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Jenssegers\Agent\Agent;
use Yansongda\Pay\Pay;

class PaymentController extends Controller
{

    /**
     * 再次支付的接口
     *
     * @param $id
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function againStore($id)
    {
        /**
         * @var $masterOrder Order
         */
        $masterOrder = Order::query()->findOrFail($id);

        // 生成支付信息
        return $this->buildPayForm($masterOrder, (new Agent)->isMobile());
    }

    /**
     * 生成支付参数的接口
     *
     * @param StoreOrderRequest $request
     * @return string
     * @throws \Exception
     */
    public function store(StoreOrderRequest $request)
    {
        DB::beginTransaction();

        try {

            // 如果有商品 id，证明是单个商品下单。
            // 否则，就是通过购物车直接下单，
            // 但无论如何都得有 address_id
            $masterOrder = $this->newMasterOrder($request->input('address_id'));

            if ($request->has('product_id')) {

                $this->storeSingleOrder($masterOrder, $request->input('product_id'), $request->input('number'));
            } else {

                $this->storeCarsOrder($masterOrder);
            }

        } catch (\Exception $e) {

            DB::rollBack();

            return back()->withErrors($e->getMessage());
        }

        DB::commit();

        // 当订单超过三十分钟未付款，自动取消订单
        $delay = Carbon::now()->addMinute(setting('order_un_pay_auto_cancel_time', 30));
        CancelUnPayOrder::dispatch($masterOrder)->delay($delay);

        // 生成支付信息
        return $this->buildPayForm($masterOrder, (new Agent)->isMobile());
    }

    /**
     * 单个商品直接下单
     *
     * @param Order $masterOrder
     * @param       $productUuid
     * @param       $number
     * @return \Illuminate\Database\Eloquent\Model
     * @throws OrderException
     */
    protected function storeSingleOrder(Order $masterOrder, $productUuid, $number)
    {
        /**
         * @var $product Product
         * @var $address Address
         */
        $product = Product::query()->where('uuid', $productUuid)->firstOrFail();

        // 明细表
        $detail = $this->buildOrderDetail($product, $number);
        $masterOrder->name = $product->name;
        $masterOrder->total = $detail['total'];
        $masterOrder->save();


        return $masterOrder->details()->create($detail);
    }


    /**
     * @param Order $masterOrder
     * @return \Illuminate\Database\Eloquent\Collection
     * @throws OrderException
     */
    protected function storeCarsOrder(Order $masterOrder)
    {
        $cars = $this->user()->cars()->with('product')->get();
        if ($cars->isEmpty()) {

            throw new OrderException('购物车为空，请选择商品后再结账');
        }

        // 明细表
        $details = $cars->map(function (Car $car) use ($masterOrder) {

            return $this->buildOrderDetail($car->product, $car->number);
        });

        // 商品的名字，用多个商品拼接
        $name = $cars->pluck('product')->pluck('name')->implode('|');
        $name = str_limit($name, 50);

        $masterOrder->name = $name;
        $masterOrder->total = $details->sum('total');
        $masterOrder->save();

        // 订单明细表创建
        $orderDetails = $masterOrder->details()->createMany($details->all());

        // 删除购物车完成
        $this->user()->cars()->delete();

        return $orderDetails;
    }


    /**
     * 实例化一个主订单
     *
     * @param $addressId
     * @return Order
     */
    protected function newMasterOrder($addressId)
    {
        /**
         * 主订单的新建
         * @var $address Address
         * @var $masterOrder Order
         */
        $address = Address::query()->find($addressId);

        $order = new Order();
        $order->consignee_name = $address->name;
        $order->consignee_phone = $address->phone;
        $order->consignee_address = $address->format();
        $order->user_id = auth()->id();

        return $order;
    }

    /**
     * 库存数量
     *
     * @param Product $product
     * @param         $number
     * @throws OrderException
     */
    protected function decProductNumber(Product $product, $number)
    {
        if ($number > $product->count) {
            throw new OrderException("[{$product->name}] 库存数量不足");
        }

        $product->setAttribute('count', $product->count - $number)
                ->setAttribute('safe_count', $product->safe_count + $number)
                ->save();
    }

    /**
     * 构建订单明细
     *
     * @param Product $product
     * @param         $number
     * @return array
     * @throws OrderException
     */
    protected function buildOrderDetail(Product $product, $number)
    {
        // 库存量减少
        $this->decProductNumber($product, $number);

        $attribute =  [
            'product_id' => $product->id,
            'number' => $number
        ];
        $attribute['price'] = $product->price;
        $attribute['total'] = ceilTwoPrice($attribute['price'] * $attribute['number']);

        return $attribute;
    }

    /**
     * 生成支付订单
     *
     * @param Order $order
     * @param       $isMobile
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function buildPayForm(Order $order, $isMobile)
    {
        // 创建订单
        $order = [
            'out_trade_no' => $order->no,
            'total_amount' => $order->total,
            'subject' => $order->name,
        ];

        $pay = Pay::alipay(config('pay.ali'));

        if ($isMobile) {

            return $pay->wap($order);
        }

        return $pay->web($order);
    }


    /**
     * @return User
     */
    protected function user()
    {
        return auth()->user();
    }

}
