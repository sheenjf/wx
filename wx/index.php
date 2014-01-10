<?php
//define your token
define("TOKEN", "xin120908");
$wechatObj = new wechatCallbackApi();
$wechatObj->responseMsg();
//$wechatObj->valid();

class wechatCallbackApi
{
   public function responseMsg()
    {
        //get post data, May be due to the different environments
        $postStr = $GLOBALS["HTTP_RAW_POST_DATA"];

        //extract post data
        if (!empty($postStr)){
                
                $postObj = simplexml_load_string($postStr, 'SimpleXMLElement', LIBXML_NOCDATA);
                $RX_TYPE = trim($postObj->MsgType);
		
                switch($RX_TYPE)
                {
                    case "text":
                        $resultStr = $this->handleText($postObj);
                        break;
                    case "event":
                        $resultStr = $this->handleEvent($postObj);
                        break;
                    default:
                        $resultStr = "Unknow msg type: ".$RX_TYPE;
                        break;
                }
                echo $resultStr;
        }else {
            echo "";
            exit;
        }
    }

    public function handleText($postObj)
    {
        $fromUsername = $postObj->FromUserName;
        $toUsername = $postObj->ToUserName;
        $keyword = trim($postObj->Content);
		$result = "";
		if(!empty( $keyword )){
			try	
			{
			$dbh = new PDO('mysql:host=localhost;dbname=wx_milch', 'wx', '2w3e', array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8';"));
			$dbh->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		// always disable emulated prepared statement when using the MySQL driver
			$dbh->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
			$sql = "SELECT COUNT(*) FROM inquiry WHERE follower_id = '" . $fromUsername . "' ORDER BY id DESC LIMIT 1";
			if ($lr = $dbh->query($sql)){
				$lrow = $lr->fetch(PDO::FETCH_BOTH);		
				if(!empty($lrow[0])){
					$sql = "SELECT session, path FROM inquiry WHERE follower_id = '" . $fromUsername . "' ORDER BY id DESC LIMIT 1";
					foreach ($dbh -> query($sql) as $row){
						$lss = intval($row['session']);
						$lpa = $row['path'];
					}
					if(time()-$lss <= 120){
						//same session
						$paa = explode(";",$lpa);
						$pa = explode("&",$paa[0]);
						$n = intval($keyword);//need check if $keyword is number!!!
						if($n>0 && $n<count($paa)){
							$kw = $paa[$n];
						}
						$content = $this->sameSession($dbh,$fromUsername,$pa,$kw);						
					}
					else{
						// new session
						$content = $this->newSession($dbh,$fromUsername,$keyword);
					}
				}
				else{
					//new follower
					$content = $this->newSession($dbh,$fromUsername,$keyword);
				}
			}
			$c = null;
			
		}
			catch(PDOException $e)
			{
				$content = "SYSTEM ERROR!\n" . $e->getMessage();
			}
		}
		return $this->responseText($postObj,$content);	
    }
	
    public function handleEvent($object)
    {
        $content = "";
        switch ($object->Event)
        {
            case "subscribe":
                $content = "Xin.";
                break;
	    case "unsubscribe":
            default :
                $content = "Unknow Event: ".$object->Event;
                break;
        }
        $result = $this->responseText($object, $content);
        return $result;
    }
    
    public function responseText($object, $content, $flag=0)
    {
        $textTpl = "<xml>
                    <ToUserName><![CDATA[%s]]></ToUserName>
                    <FromUserName><![CDATA[%s]]></FromUserName>
                    <CreateTime>%s</CreateTime>
                    <MsgType><![CDATA[%s]]></MsgType>
                    <Content><![CDATA[%s]]></Content>
                    <FuncFlag>%d</FuncFlag>
                    </xml>";
	$msgType = "text";
        $result = sprintf($textTpl, $object->FromUserName, $object->ToUserName, time(),$msgType, $content, $flag);
        return $result;
    }

    private function checkSignature()
    {
        $signature = $_GET["signature"];
        $timestamp = $_GET["timestamp"];
        $nonce = $_GET["nonce"];    
                
        $token = TOKEN;
        $tmpArr = array($token, $timestamp, $nonce);
        sort($tmpArr);
        $tmpStr = implode( $tmpArr );
        $tmpStr = sha1( $tmpStr );
        
        if( $tmpStr == $signature ){
            return true;
        }else{
            return false;
        }
    }
	
	private function newSession(PDO $dbh,$fromUsername,$msg)
	{
		$sql = "SELECT DISTINCT category FROM catalog";
		$i=1;
		$content = "";
		$pa = array();
		$pa[] = "ALL";
		foreach($dbh->query($sql) as $row){
			$content .= " [".$i."]  ".$row['category']."\n";
			$i++;
			$pa[] = $row['category'];
		}
		$path = implode(";",$pa);
		$stmt = $dbh -> prepare("INSERT INTO inquiry (follower_id,session,msg,path) values (:fid,:ss,:msg,:pt)");
		$stmt->bindParam(':fid',$fromUsername);
		$stmt->bindParam(':ss',strval(time()));
		$stmt->bindParam(':msg',$msg);
		$stmt->bindParam(':pt',$path);
		$stmt->execute();
		$stmt = null;
		
		return $content;
	}
	private function sameSession(PDO $dbh,$fromUsername,$pa,$kw)
	{
		$sa = array("","category","brand","model");
		$c = count($pa);
		$sql = "";
		$content = "";
		switch($c){
			case 1:				
				$sql = "SELECT DISTINCT ". $sa[2] ." FROM catalog WHERE ". $sa[1]."='".$kw."'";				
				$content = $kw.":\n";
				break;
			case 2:
				$sql = "SELECT DISTINCT ". $sa[3] ." FROM catalog WHERE ". $sa[1]."='".$pa[1]."' AND ".$sa[2]."='".$kw."'";
				$content = $pa[1]."-".$kw.":\n";
				break;
			case 3:
				$sql = "SELECT quantity, price, expired_date FROM store WHERE catalog_id in (SELECT id FROM catalog WHERE ". $sa[1]."='".$pa[1]."' AND ".$sa[2]."='".$pa[2]."' AND ".$sa[3]."='".$kw."')";
				$content = $pa[1]."-".$pa[2]."-".$kw.":\n";
				break;
			default:
				$sql = "SELECT DISTINCT category FROM catalog";
				break;
		}
		$pa[] = $kw;
		$pas = implode("&",$pa);
		$pam = array($pas);
		$i = 1;		
		if($c<3){
			foreach($dbh->query($sql) as $row){
				$content .= " [".$i."]  ".$row[0]."\n";
				$i++;
				$pam[] = $row[0];
			}
			$path = implode(";",$pam);
			$stmt = $dbh -> prepare("INSERT INTO inquiry (follower_id,session,msg,path) values (:fid,:ss,:msg,:pt)");
			$stmt->bindParam(':fid',$fromUsername);
			$stmt->bindParam(':ss',strval(time()));
			$stmt->bindParam(':msg',$kw);
			$stmt->bindParam(':pt',$path);
			$stmt->execute();
			$stmt = null;		
		}
		else{
			foreach($dbh->query($sql) as $row){
				$content .= " [".$i."] ".$row['quantity'].",RMB".$row['price']."(".date('y/m',strtotime($row['expired_date'])).")\n";
				$i++;
							
			}
		}
		return $content;
	//	return $sql." ".$kw;
	}
}
?>
