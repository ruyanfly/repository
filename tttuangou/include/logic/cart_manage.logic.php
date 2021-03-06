<?php
/**
 * @copyright (C)2014 Cenwor Inc.
 * @author Cenwor <www.cenwor.com>
 * @package php
 * @name cart_manage.logic.php
 * @date 2016-08-16 17:36:22
 */





class Cart_manageLogic
{
	private $_clientCart,$_serviceCart;
	function __construct($user_id=MEMBER_ID)
	{
		$this->_serviceCart = new ServiceCart();
		$this->_clientCart  = new ClientCart();
		#购物车合并
                

		if($user_id > 0)
		{
			$clientCartItems = $this->_clientCart->DeleteItems();
			$serviceCartItems = $this->_serviceCart->GetItems($user_id);
			foreach($clientCartItems as $key => $value)
			{
				if(isset($serviceCartItems[$value['id']]))
					unset($clientCartItems[$key]);
				$this->_serviceCart->AddItem($value['id'], $value['num']);
			}
		}
	}
	
	function AddItem($productId,$num,$user_id=MEMBER_ID)
	{
		if($user_id > 0)
			return $this->_serviceCart->AddItem($productId,$num,$user_id);
		else
            return $this->_clientCart->AddItem($productId,$num,$user_id);
	}
	
	function GetItems($user_id=MEMBER_ID)
	{
		if($user_id > 0)
		{
			return $this->_serviceCart->GetItems($user_id);
		}
		else
		{
			return $this->_clientCart->GetItems($user_id);
		}
	}

	function CountItems() {
		return count($this->GetItems());
	}
	
	#@param int $id 商品id  $_GET['id'] 用户id
	function RemoveItem($id,$user_id=MEMBER_ID)
	{
		if($user_id > 0)
			return $this->_serviceCart->RemoveItem($id,$user_id);
		else
			return $this->_clientCart->RemoveItem($id,$user_id);
	}
	
	function RemoveAllItem()
	{
	    $items = $this->GetItems();
	    if(empty($items))
	        return false;
	    foreach($items as $v)
	    {
	        $this->RemoveItem($v['product_id']);
	    }
	}
	
	function filter($products,$user_id=MEMBER_ID)
	{
		if($user_id > 0)
			return $this->_serviceCart->filter($products);
		else
			return $this->_clientCart->GetItems($products);
	}
	
	function ProduceOrder()
	{
		return $this->_serviceCart->ProduceOrder();
	}
}


abstract class ACart
{
    abstract function AddItem($productId,$num);
    abstract function RemoveItem($productId);
    abstract function GetItems();
    function checkProduct($productId)
    {
    	$product = logic('product')->GetOne($productId);
    	if(empty($product))
    		throw new Exception('该产品不存在');
    }
}

#已经登录情况下的加入购物车
class ServiceCart extends ACart
{
	
	function GetItems($uid=MEMBER_ID)
	{
		$uid = (int) $uid;

		$sql = "select * from ".table('cart')." c
														right join ".table('cart_item')." ci on c.user_id = ci.cart_id 
														left join  ".table('product')." p on ci.product_id = p.id 
														where user_id={$uid} and state=1";
        return $this->products = $product = dbc(DBCMax)->query($sql)->done();
	}
	
	#输出页面购物车列表所需的数据
	#@example filter($this->GetItems())
	function filter($products)
	{
		$return = array();
		if(empty($products))
			return $products;
		foreach ($products as $k => &$v)
		{
			
			$return[$v['id']]['id'] = $v['id'];
			$return[$v['id']]['nowprice'] = $v['nowprice'];
			$return[$v['id']]['flag'] = $v['flag'];
			$return[$v['id']]['sellerid'] = $v['sellerid'];
			$v['img'] = strtok($v['img'],',');
			if($v['img'] == false){
                $v['img'] = '';
            }
			
			

			$return[$v['id']]['img_url'] = $v['img'];
			$return[$v['id']]['num'] = $v['num'] ? $v['num'] : 1;
		    $return[$v['id']]['oncemin'] = $v['oncemin'];
		    $return[$v['id']]['type'] = $v['type'];
		    $return[$v['id']]['oncemax'] = $v['oncemax'];
		    $return[$v['id']]['oncemin'] = $v['oncemin'];
		    if($v['maxnum'] == 0 )
		        $return[$v['id']]['surplus'] = 9999;
		    else
		      $return[$v['id']]['surplus'] = logic('product')->Surplus($v['maxnum'],logic('product')->SellsCount($v['id']));		    $return[$v['id']]['weight'] = ($v['weightsrc'] ? $v['weightsrc'] : $v['weight']);

		    if ($v['type'] == 'stuff' && $v['weight'] > 0)
			{
				$vwu = ($v['weight'] >= 1000) ? 'kg' : 'g';
				$return[$v['id']]['weight_view'] = ($v['weight'] * (($vwu == 'kg') ? 0.001 : 1)) . ' Kg';
			}
		}
		return $return;
	}
	
	
    function AddItem($productId,$num,$userId=MEMBER_ID)
    {
    	$productId = (int) $productId;
    	$num = (int) $num;
    	$userId = (int) $userId;

    	$this->checkProduct($productId);
    	$product = logic('product')->GetOne($productId);

    	    	if(false == dbc(DBCMax)->select('cart')->where(array('user_id'=>$userId))->limit(1)->done()) {
	    	dbc()->SetTable(table('cart'));
			dbc()->Insert(array('create_date'=>time(),'user_id'=>$userId));
		}

		$sql = "insert into ".table('cart_item')."(cart_id, nowprice, num, product_id, product_name, seller_id) 
												values({$userId},{$product['nowprice']},{$num},{$productId},'" .(addslashes($product['name'])). "',{$product['sellerid']}) 
												on duplicate key update num = num+{$num}";
		return dbc(DBCMax)->query($sql)->done();
    }
    

    function RemoveItem($productId,$userId=MEMBER_ID)
    {
    	    	return logic('cart_item')->delete($productId,$userId);
    }
    
    #根据购物车中商品项和商品总价生成订单初始数据
    function ProduceOrder()
    {
    	$items = $this->filter($this->GetItems());
    }
}

#未登录的情况下加入购物车
class ClientCart  extends ACart
{
	private $productId,$products = array();
	private $cookie_name = '__cart_view_id';	
	
	function __construct()
	{
	    $products = handler('cookie')->GetVar($this->cookie_name);
		if(!empty($products))
		{	
			$this->products = unserialize($products);
		}
	}
	
	function SetProductId($id)
	{
		return $this->productId = $id;
	}
	
	function GetProductId()
	{
		return $this->productId;
	}
	
	#@param array $product 二维数组
	function mergeMultiProducts($products)
	{
		
	}
	#产品合并，如果相同，数量相加
	#@param array $product 一维数组
	function MergeProducts($product)
	{
		if(isset($this->products[$product['id']]))
		{
			$this->products[$product['id']]['num'] = $this->products[$product['id']]['num']+$product['num'];
		}
		else
		{
			$this->products[$product['id']] = $product;
		}
		return $this->products;
	}
	
	#数据合并，如果遇到同类产品，那么计数器num加
	function PushProducts($product)
	{
		return $this->products = array_merge($this->products , $product);
	}
	
	function GetItems()
	{
		return $this->products;
	}
	
    function AddItem($productId,$num)
    {
    	$this->checkProduct($productId);
    	$product = logic('product')->GetOne($productId);
    	$product = $this->filter($product);
		$product['num'] = $num; 
    	$products = $this->MergeProducts($product);
    	return handler('cookie')->SetVar($this->cookie_name, serialize($products), 864000);
        
    }
    
    function filter($products)
    {
    	$return = array();
    	if(empty($products))
    		return $return;
    	$return['id']       = $products['id'];
	    $return['nowprice'] = $products['nowprice'];
	    $return['flag']     = $products['flag'];
	    $return['sellerid'] = $products['sellerid'];
        $products['img']    = strtok($products['img'],',');
		        if($products['img'] == false){
            $products['img'] = '';
        }
				$return['img']  = $products['img'];
		return $return;
    }
    
	function RemoveItem($productId)
    {
    	if(isset($this->products[$productId]))
    	{
    		unset($this->products[$productId]);
    		handler('cookie')->SetVar($this->cookie_name, serialize($this->products), 864000);
    	}
    	return $this;
    }
    
    function DeleteItems()
    {
    	$deleteProducts = $this->products;
    	handler('cookie')->DeleteVar($this->cookie_name);
    	return $deleteProducts;
    }
}
?>