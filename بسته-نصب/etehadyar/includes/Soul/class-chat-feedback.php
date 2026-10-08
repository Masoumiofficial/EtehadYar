<?php
defined('ABSPATH')||exit;
class EAIW_Chat_Feedback{
 public static function summary(){global $wpdb;$t=$wpdb->prefix.'eaiw_chat_feedback';$x=['total'=>0,'positive'=>0,'negative'=>0,'rate'=>0,'recent'=>[]];if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$t))!==$t)return $x;$x['total']=(int)$wpdb->get_var("SELECT COUNT(*) FROM $t");$x['positive']=(int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE rating=1");$x['negative']=$x['total']-$x['positive'];$x['rate']=$x['total']?round(($x['positive']/$x['total'])*100,1):0;$x['recent']=$wpdb->get_results("SELECT message,rating,created_at FROM $t ORDER BY id DESC LIMIT 10",ARRAY_A);return $x;}
 public static function get($id){global $wpdb;$t=$wpdb->prefix.'eaiw_chat_feedback';return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d",(int)$id),ARRAY_A);}
 public static function negative($limit=30){global $wpdb;$t=$wpdb->prefix.'eaiw_chat_feedback';if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$t))!==$t)return [];return $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE rating=-1 ORDER BY id DESC LIMIT %d",max(1,min(100,(int)$limit))),ARRAY_A);}
 public static function save($session,$rating,$message=''){global $wpdb;$rating=(int)$rating;if(!in_array($rating,[1,-1],true))return false;$t=$wpdb->prefix.'eaiw_chat_feedback';return (bool)$wpdb->insert($t,['session_id'=>sanitize_text_field($session),'rating'=>$rating,'message'=>sanitize_text_field($message)]);}
}
