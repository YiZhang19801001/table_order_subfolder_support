<?php

namespace App\Http\Controllers;

use App\Events\ConfirmOrder;
use App\Events\UpdateOrder;
use App\Order;
use App\OrderExt;
use App\OrderHistory;
use App\OrderOption;
use App\OrderProduct;
use App\OrderTableLinksub;
use App\OrderTotal;
use App\Product;
use App\ProductAddType;
use App\ProductDescription;
use App\TableLink;
use App\TempOrder;
use App\TempOrderItem;
use App\TempPickedChoice;
use App\TempPickedOption;
use DateTime;
use DateTimeZone;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct()
    {
    }

    /** fetch the up to date order list once */
    public function getCart(Request $request)
    {
        /**validate users */
        //check if this QRcode contain all infos with correct format
        if ($request->cdt == null || $request->v == null) {
            return response()->json(["message" => "this QR Code is invalid, please contact staff!"], 400);
        }



        //mapping value for further valided
        $cdt = $request->cdt;
        $v = $request->v;

        //check if this QRcode valid in DB
        $new_table_link = TableLink::where('validation', $v)->first();
		
		
        //$new_table_link === null => QRcode is not valid
        if ($new_table_link === null || $new_table_link->status != 0) {
            return response()->json(["message" => "this QR Code is invalid, please contact staff!"], 400);
        }
		// return response()->json([$new_table_link]);
		
        //check timestamp
        if ($new_table_link !== null) {
            $tz = 'Australia/Sydney';

            //reformat income time
            $time = strtotime($cdt);
            $day = date('y-m-d', $time);

            //reformat time in DB
            $time_in_db = strtotime($new_table_link->link_generate_time);
            $day_in_db = date('y-m-d', $time_in_db);

            //reformat today's date
            $today = new DateTime("now", new DateTimeZone($tz));
            $time_today = $today->format('y-m-d');

            //return array('day' => $day, 'db' => $day_in_db, 'today' => $time_today);
            //check matched or not
            if ($day < $time_today) {
                return response()->json(["message" => "this QR Code is expired, please contact staff!"], 400);

            } else if ($day != $day_in_db && $day == $time_today) {
                return response()->json(["message" => "this QR Code is not found, please try it later or contact staff!"], 400);
            } else if ($day != $day_in_db) {
                return response()->json(["message" => "this QR Code is invalid, please contact staff!"], 400);

            }
        }
        /**end validation */


        $order = $this->fetchOrderListHelper($request->orderId, $request->tableId, $request->lang);
        return response()->json($order);

    }

    public function fetchOrderListHelper($order_id, $table_id, $lang)
    {
        $order = TempOrder::where('id', $request->order_id)->first();
        if ($order === null) {
            $order = new TempOrder;

            $order["id"] = $request->order_id;
            $order["table_number"] = $request->table_id;

            $order["order_list_string"] = json_encode(['pendingList' => [], 'historyList' => []]);
            $order->save();
        }

        return response()->json(json_decode($order));


        /**1. from 'order_id' fetching order_items
         * 2. from 'order_item' table get 'product_id',
         * according to those ids get details of products from product table
         * 3. 22-nov-2018, add show history function
         */
        $order = []; //result container
        $orderHistory = []; //result of history container

        /** temp_order_items
         * [id:int(10)][quantity:int(11)][product_id:int(11)][order_id:int(11)]
         */
        $arr_order_items_confirmed = TempOrderItem::where('order_id', $order_id)->where('quantity', '>', 0)->where('oc_order_id', '!=', null)->get();

        if (count($arr_order_items_confirmed) > 0) {
            foreach ($arr_order_items_confirmed as $key => $item) {
                $linksub = OrderTableLinksub::where('order_id', $item['oc_order_id'])->first();
                if ($linksub == null || $linksub->sub_status > 1) {
                    unset($arr_order_items_confirmed[$key]);
                }
            }
        }
        $orderHistory = $this->addDetailsForOrderListHelper($arr_order_items_confirmed, $lang);

        $arr_order_items_pendding = TempOrderItem::where('order_id', $order_id)->where('quantity', '>', 0)->where('oc_order_id', null)->get();

        $order = $this->addDetailsForOrderListHelper($arr_order_items_pendding, $lang);

        return array('pendingList' => $order, 'historyList' => $orderHistory);

    }

    public function addDetailsForOrderListHelper($arr_order_items, $lang)
    {
        $order = [];
        /**if $arr_order_items is empty return empty array() */
        if (count($arr_order_items) < 1) {
            return $order;
        }

        //$lang = config('app.lang');
        $mode = config('app.show_options');
        /**if $arr_order_items is not empty, build the result array with details */
        /** order_item details need: [name][quantity][price] full detail mode only [ext][option]*/
        foreach ($arr_order_items as $order_item) {
            $new_orderList_ele = array();
            $targe_product = ProductDescription::where('product_id', $order_item["product_id"])->where('language_id', $lang)->first();
            /** if no chinese version row, return en version */
            if ($targe_product === null) {
                $targe_product = ProductDescription::where('product_id', $order_item["product_id"])->first();
            }
            $p = Product::where('product_id', $order_item["product_id"])->first();

            /**make price */
            //fetch price first
            $price = $p->price;
            $posOfdecimal = strpos($price, ".");
            //cut after 2 digts decimal point
            $length = $posOfdecimal + 3;
            $price = substr($price, 0, $length);
            /**END */

            //mapping values
            // $new_orderList_ele["item"]["order_item_id"] = $order_item["id"];
            $new_orderList_ele["item"]["product_id"] = $order_item["product_id"];
            $new_orderList_ele["quantity"] = $order_item["quantity"];
            $new_orderList_ele["item"]["name"] = $targe_product["name"];
            $new_orderList_ele["item"]["description"]=$targe_product["description"];
            $new_orderList_ele["item"]["price"] = $price;
            $new_orderList_ele["item"]["upc"] = $p->upc;
            $image_path = '/table/public/images/items/' . $p->image;
            $new_orderList_ele["item"]["image"] = "";
            if ($p->image === null || !file_exists($_SERVER['DOCUMENT_ROOT'] . $image_path)) {
                $new_orderList_ele["item"]["image"] = 'default_product.jpg';

            } else {

                $new_orderList_ele["item"]["image"] = $p->image;
            }
            // $new_orderList_ele["item"]["image"] = $p->image === null ? 'default_product.jpg' : $p->image;
            /**append options & exts only needed when mode is show options */

            if ($mode) {
                //ToDo: add price for choice
                /**temp_pickedchoices
                 * [id:int(10)][order_item_id:int(11)][choice_type:varchar(255)][picked_Choice:varchar(255)]
                 */
                $pickedChoices = TempPickedChoice::where('order_item_id', $order_item["id"])->get();

                $productChoiceList = [];

                foreach ($pickedChoices as $pickChoice) {
                    $type = ProductAddType::where('name', $pickChoice["choice_type"])->first();

                    //$choices = Product_ext::where('type',$type->add_type_id)->get();

                    array_push($productChoiceList, array(
                        'choices'=>$product_choices,
                        "type" => $type->name,
                        "pickedChoice" => $pickChoice["picked_Choice"],
                        "price" => $pickChoice["price"],
                        "type_id" => $type->id));
                }

                $new_orderList_ele["item"]["choices"] = $productChoiceList;

                // /**grab all information for options */
                // $pickedOptions = TempPickedOption::where('order_item_id', $order_item["id"])->get();

                $productOptionList = [];
                // foreach ($pickedOptions as $pickOption) {
                //     /**get optionValues */
                //     /** option_id && product_id can found unique [product_option_value_id] [price] [option_value_id]
                //      * [option_value_name] ->use [option_value_id] find this from [oc_option_value_description]
                //      * [option_value_sort_order] ->use [option_value_id] find this from [oc_option_value]
                //      */

                //     //Todo: may need list of options
                //     // $optionValues = array();
                //     // foreach ($variable as $key => $value) {
                //     //     # code...
                //     // }

                //     array_push($productOptionList, array(
                //         "option_id" => $pickOption["option_id"],
                //         "option_name" => $pickOption["option_name"],
                //         "pickedOption" => $pickOption["pickedOption"],
                //         "price" => $pickOption["price"],
                //         "product_option_value_id" => $pickOption["product_option_value_id"],
                //         //"option_values"             =>$optionValues
                //     ));
                // }
                $new_orderList_ele["item"]["options"] = $productOptionList;
            }

            /**End */
            array_push($order, $new_orderList_ele);
        }
        return $order;
    }

    public function createOrderHelper($new_item, $orderId)
    {

        $mode = config('app.show_options');

        $new_order_item = new TempOrderItem;
        $new_order_item->quantity = 1;
        $new_order_item->product_id = $new_item["product_id"];
        $new_order_item->order_id = $orderId;
        $new_order_item->save();

        if (!$mode) {
            return; //if $mode is no show options, stop here!!
        }

        foreach ($new_item["choices"] as $choice) {
            $new_pickedChoice = new TempPickedChoice;

            $pickChoiceDecode = $choice["pickedChoice"];

            $new_pickedChoice->product_ext_id = $pickChoiceDecode->product_ext_id;
            $new_pickedChoice->order_item_id = $new_order_item->id;
            $new_pickedChoice->choice_type = $choice["type"];
            $new_pickedChoice->picked_Choice = $pickChoiceDecode->name;
            $new_pickedChoice->price = $pickChoiceDecode->price;

            $new_pickedChoice->save();
        }

        // foreach ($new_item["options"] as $option) {
        //     $new_pickedOption = new TempPickedOption;

        //     $new_pickedOption->order_item_id = $new_order_item->id;
        //     $new_pickedOption->product_option_value_id = $option["product_option_value_id"];
        //     $new_pickedOption->option_name = $option["option_name"];
        //     $new_pickedOption->pickedOption = $option["pickedOption"];
        //     $new_pickedOption->price = $option["price"];
        //     $new_pickedOption->product_id = $new_item["product_id"];
        //     $new_pickedOption->option_id = $option["option_id"]["option_id"];

        //     $new_pickedOption->save();
        // }
    }

    public function confirmOrder(Request $request)
    {
        /**request is an array of  */
        //get new order
        $new_order = $this->createOcOrderHelper($request);
        $order_id = $new_order->id;

        $value = $new_order->total;
        //create record in oc_order_history
        $this->createOcOrderHistoryHelper($order_id);

        //create record in oc_order_total
        $this->createOrderTotalHelper($order_id, $value);

        //create record in oc_order_product
        $this->createOrderProductHelper($request->orderList, $order_id);

        //create record in oc_table_linksub
        $this->createOrderLinkSubHelper($new_order, $request->v);

        //update temp_order_item
        $returnHistoryListString = $this->changeTempOrderItemsStatus($request->order_id, $request->orderList);

        broadcast(new ConfirmOrder($request->orderId));
        return response()->json(["historyList" => json_decode($returnHistoryListString)], 200);

    }

    public function changeTempOrderItemsStatus($id, $orderList)
    {

        $order = TempOrder::where('id', $id)->first();
        $orderArr = json_decode($order->order_list_string);
        $orderHistoryListArr = $orderArr->historyList;
        $orderArr->historyList = array_merge($orderHistoryListArr, $orderList);
        $orderArr->pendingList = [];
        $order->order_list_string = json_encode($orderArr);
        $order->save();

        return json_encode($orderArr->historyList);
    }

    public function createOrderLinkSubHelper($new_order, $v)
    {
        $new_order_linksub = new OrderTableLinksub;
        $new_order_linksub->sub_add_time = $new_order->date_added;
        $new_order_linksub->downloaded = 0;
        $new_order_linksub->order_id = $new_order->id;
        $new_order_linksub->sub_status = 1;

        $new_table_link = TableLink::where('validation', $v)->first();
        $new_order_linksub->link_id = $new_table_link->link_id;

        $new_order_linksub->save();
    }

    public function createOcOrderHelper($request)
    {
        $tz = 'Australia/Sydney';
        $timestamp = time();
        $dt = new DateTime("now", new DateTimeZone($tz)); //first argument "must" be a string
        $dt->setTimestamp($timestamp); //adjust the object to correct timestamp

        /**create order in oc_order */
        $new_order = new Order;
        $new_order->invoice_no = 0;
        $new_order->invoice_prefix = "INV-2013-00";
        $new_order->store_id = $request->store_id; //4
        $new_order->store_name = $request->store_name; //Monkey King Thai Restaurant
        $new_order->store_url = $request->store_url; //http://192.168.1.220/
        $new_order->customer_id = 0;
        $new_order->customer_group_id = 1;
        $new_order->firstname = " ";
        $new_order->lastname = " ";
        $new_order->email = "tableorder@order2.com";
        $new_order->telephone = " ";
        $new_order->fax = " ";
        $new_order->custom_field = " ";
        $new_order->payment_firstname = " ";
        $new_order->payment_lastname = " ";
        $new_order->payment_company = " ";
        $new_order->payment_address_1 = " ";
        $new_order->payment_address_2 = " ";
        $new_order->payment_city = " ";
        $new_order->payment_postcode = " ";
        $new_order->payment_country = " ";
        $new_order->payment_country_id = 0;
        $new_order->payment_state = " ";
        $new_order->payment_state_id = 0;
        $new_order->payment_suburb = " ";
        $new_order->payment_suburb_id = 0;
        $new_order->payment_address_format = " ";
        $new_order->payment_custom_field = " ";
        $new_order->payment_code = "cod";
        $new_order->payment_method = "DineIn";
        $new_order->shipping_firstname = " ";
        $new_order->shipping_lastname = " ";
        $new_order->shipping_email = "tableorder@order2.com";
        $new_order->shipping_telephone = " ";
        $new_order->shipping_company = " ";
        $new_order->shipping_address_1 = " ";
        $new_order->shipping_address_2 = " ";
        $new_order->shipping_city = " ";
        $new_order->shipping_postcode = " ";
        $new_order->shipping_country = " ";
        $new_order->shipping_country_id = 0;
        $new_order->shipping_state = " ";
        $new_order->shipping_state_id = 0;
        $new_order->shipping_suburb = " ";
        $new_order->shipping_suburb_id = 0;
        $new_order->shipping_address_format = " ";
        $new_order->shipping_custom_field = " ";
        $new_order->shipping_method = "DineIn";
        $new_order->shipping_orderTime = $dt->format('H:i');
        $new_order->shipping_orderDate = $dt->format("D, d M y");
        $new_order->shipping_orderWhen = "now";
        $new_order->shipping_code = " ";
        $new_order->comment = " ";
        $new_order->total = $request->total;
        $new_order->order_status_id = 1;
        $new_order->affiliate_id = 0;
        $new_order->commission = 0.0000;
        $new_order->marketing_id = 0;
        $new_order->tracking = " ";
        $new_order->language_id = 1;
        $new_order->currency_id = 4;
        $new_order->currency_code = "AUD";
        $new_order->currency_value = 1.000000;
        //Todo: fetch from request
        $new_order->ip = "192.168.1.220";
        $new_order->forwarded_ip = " ";
        //Todo: fetch from request
        $new_order->user_agent = "Mozilla/5.0 (Windows NT 5.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/49.0.2623.112 Safari/537.36";
        //Todo: fetch from accept_language
        $new_order->accept_language = "en-GB,en-US;q=0.8,en;q=0.6";
        $new_order->date_added = $dt->format("y-m-d h:i:s");
        $new_order->date_modified = $dt->format("y-m-d h:i:s");

        //save to database
        $new_order->save();

        //return id
        return $new_order;
    }

    public function createOcOrderHistoryHelper($order_id)
    {
        $tz = 'Australia/Sydney';
        $timestamp = time();
        $dt = new DateTime("now", new DateTimeZone($tz)); //first argument "must" be a string
        $dt->setTimestamp($timestamp); //adjust the object to correct timestamp

        $new_order_history = new OrderHistory;
        $new_order_history->order_id = $order_id;
        $new_order_history->notify = 0;
        //Todo: read from new order??
        $new_order_history->order_status_id = 1;
        $new_order_history->comment = " ";
        $new_order_history->date_added = $dt->format("y-m-d h:i:s");

        $new_order_history->save();
    }

    public function createOrderTotalHelper($order_id, $value)
    {
        $new_order_total_1 = new OrderTotal;
        $new_order_total_1->order_id = $order_id;
        $new_order_total_1->code = "sub_total";
        $new_order_total_1->title = "Sub-Total";
        $new_order_total_1->value = $value;
        $new_order_total_1->sort_order = 1;
        $new_order_total_1->save();

        $new_order_total_2 = new OrderTotal;
        $new_order_total_2->order_id = $order_id;
        $new_order_total_2->code = "shipping";
        $new_order_total_2->title = "Dive-In";
        $new_order_total_2->value = $value;
        $new_order_total_2->sort_order = 3;
        $new_order_total_2->save();

        $new_order_total_3 = new OrderTotal;
        $new_order_total_3->order_id = $order_id;
        $new_order_total_3->code = "total";
        $new_order_total_3->title = "Total";
        $new_order_total_3->value = $value;
        $new_order_total_3->sort_order = 9;
        $new_order_total_3->save();
    }

    public function createOrderProductHelper($orderList, $order_id)
    {
        $arr_order_items = $orderList;

        foreach ($arr_order_items as $order_product) {
            $new_order_product = new OrderProduct;
            $new_order_product->order_id = $order_id;
            $new_order_product->product_id = $order_product["item"]["product_id"];
            $new_order_product->model = 1;
            $new_order_product->quantity = $order_product["quantity"];
            $new_order_product->name = $order_product["item"]["name"];
            $new_order_product->price = $order_product["item"]["price"];
            $new_order_product->total = $order_product["quantity"] * (float) $order_product["item"]["price"];
            $new_order_product->tax = 0;
            $new_order_product->reward = 0;

            $new_order_product->save();

            if (config('app.show_options')) {
                /**picked choices */
                foreach ($order_product["item"]["choices"] as $choice) {
                    $new_order_ext = new OrderExt;
                    $decodeChoice = json_decode($choice["pickedChoice"]);
                    // $new_order_ext->product_ext_id = $choice["product_ext_id"];
                    $new_order_ext->product_ext_id = $decodeChoice->product_ext_id;

                    $new_order_ext->order_product_id = $new_order_product->id;
                    $new_order_ext->product_id = $order_product["item"]["product_id"];

                    $new_order_ext->save();
                }
                /**store picked options in DB*/
                foreach ($order_product["item"]["options"] as $option) {
                    $new_order_option = new OrderOption;
                    $new_order_option->order_id = $order_id;
                    $new_order_option->order_product_id = $new_order_product->id;

                    $new_order_option->product_option_id = $option["option_id"];

                    $new_order_option->product_option_value_id = $option["product_option_value_id"];
                    $new_order_option->name = $option["option_name"];
                    $new_order_option->value = $option["pickedOption"];
                    $new_order_option->type = "radio";

                    $new_order_option->save();
                }
            }

        }
    }

    public function dryOrderItem($new_item)
    {
        $pickedChoicesArray = [];
        if ($new_item['item']['choices']['pickedChoice'] !== null) {
            foreach ($new_item['item']['choices']['pickedChoice'] as $pickedChoicesItem) {
                array_push($pickedChoicesArray, $pickedChoicesItem['product_ext_id']);
            }
        }
        $new_item_choices = [];
        foreach ($new_item['item']['choices'] as $choicesItem) {
            array_push($new_item_choices, array(
                'type_id' => $choicesItem['type_id'],
                'pickedChoice' => $pickedChoicesArray,
            ));
        }
        return array('product_id'=>$new_item['product_id'],'choices'=>$new_item_choices);
    }
    public function update(Request $request)
    {
        broadcast(new UpdateOrder($request->orderId, $request->orderItem, $request->userId, $request->action));
        $mode = config('app.show_options');
        $new_item = dryOrderItem($request->orderItem);
        $orderRow = TempOrder::where('id', $request->orderId)->first();
        // if $order_list_string is null or empty add straight away
        if ($orderRow === null) {
            $orderRow = new TempOrder;
            $newOrderItem = ['item' => $new_item, 'quantity' => 1];
            $tempArr = array('pendingList' => [$newOrderItem], 'historyList' => []);
            $orderRow->order_list_string = json_encode($tempArr);
            $orderRow->id = $request->orderId;
            $orderRow->table_number = $request->tableId;
        } else {
            $flag = false;
            $order_list_string = $orderRow->order_list_string;
            $orderArr = json_decode($order_list_string);
            $orderObject = $orderArr->pendingList;
            foreach ($orderObject as $orderItem) {
                
                if ($orderItem->item->product_id === $new_item["product_id"]) {
                    $flag = true;
                    // if (count($orderItem->item->options) > 0) {
                    //     for ($i = 0; $i < count($orderItem->item->options) - 1; $i++) {
                    //         if ($orderItem->item->options[i]["pickedOption"] !== $new_item["options"][i]["pickedOption"]) {
                    //             $flag = false;
                    //             break;
                    //         }
                    //     }
                    // }
                    if ($flag === false || count($orderItem->item->choices) < 1) {
                        break;
                    } else {
                        for ($i = 0; $i < count($orderItem->item->choices) - 1; $i++) {
                            if ($orderItem->item->choices[i]["pickedChoice"] != $new_item["choices"][i]["pickedChoice"]) {
                                $flag = false;
                                break;
                            }
                        }
                    }
                }
                if ($flag) {
                    if ($request->action === 'add') {
                        $orderItem->quantity++;
                    } else if ($request->action === 'sub' && $orderItem->quantity > 1) {
                        $orderItem->quantity--;
                    } else if ($request->action === 'sub' && $orderItem->quantity <= 1) {
                        $index = array_search($orderItem, $orderObject);
                        array_splice($orderObject, $index, 1);
                    }
                    $orderArr->pendingList = $orderObject;
                    $orderRow->order_list_string = json_encode($orderArr);
                    break;
                }
            }
            if (!$flag && $request->action === 'add') {
                array_push($orderObject, array('item' => $new_item, 'quantity' => 1));
                $orderArr->pendingList = $orderObject;
                $orderRow->order_list_string = json_encode($orderArr);
            }
        }
        $orderRow->save();
    }
}
