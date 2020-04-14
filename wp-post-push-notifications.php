<?php
/**
 * Plugin Name: WP Post Mobile Push Notifications Handler
 * Plugin URI: https://github.com/aitriago/wp-post-push-notifications
 * Description: This plugin allows to register the FirebaseID for push notification and handle which post category will be pushed
 * Version: 1.0.0
 * Author: Aníbal Itriago
 * Author URI: https://github.com/aitriago/
 * License: GPL3
 */

class WpPostPushNotifications
 {
    private  $wpdb;
    private $_FIREBASE_ID="FIREBASE ID";
    private $_NOTIFICATION_TITLE="NOTIFICATION_TITLE";
    function __construct( ) {
        $this->wpdb=$GLOBALS['wpdb'];
        $this->init_db();
        $this->run();
    }
    public function run()
    {
        add_action( 'wp_ajax_notify_now', array( $this, 'notify_now' ) );
        add_action( 'wp_ajax_nopriv_register_device',array( $this, 'register_device'));
        $this->register_wpn_scripts();
        add_action('plugins_loaded', array($this, 'enqueue_wpn_scripts'));
        add_action('plugins_loaded', array($this, 'enqueue_wpn_styles'));
        

        // Setup filter hook to show Read Me Later link
        add_filter( 'the_excerpt', array( $this, 'wpn_button' ) );
        add_filter( 'the_content', array( $this, 'wpn_button' ) );
        add_action('wp_post_push_notification_cron_job',array($this,'exec_push_notification'));
        // Sets crontab filter
        // Setup Ajax action hook
    }
    public function write_log($log)
    {
        if (!function_exists('write_log')) {
            if (is_array($log) || is_object($log)) {
                error_log(print_r($log, true));
            } else {
                error_log($log);
            }
        }else{
            write_log($log);
        }
    }

    public function exec_push_notification()
    {
        $notifications=$this->wpdb->get_results("SELECT id, permalink, title FROM  `{$this->wpdb->prefix}notifications_post` WHERE ISNULL( `notification_date`) ORDER BY  `notification_date` ASC", ARRAY_A);        
        $users=$this->wpdb->get_results("SELECT id, firebase_id FROM `{$this->wpdb->prefix}notifications_registered_users`",ARRAY_A);
        $sent=[];
        foreach($notifications as $notification){
            $msg =[
                'body'	=>  preg_replace_callback("/(&#[0-9]+;)/", function($m) { return mb_convert_encoding($m[1], "UTF-8", "HTML-ENTITIES"); },$notification['title']),
                'title'	  => $this->_NOTIFICATION_TITLE,
                'sound'=>'notification',
            ];
            $registration_ids=[];
            foreach($users as $ndx=>$user){
                array_push($registration_ids, $user['firebase_id']);
                if(sizeof($registration_ids)==499){
                    $fields=[
                        'notification'=>$notification,
                        'body'=>[
                            "notification"=>$msg,
                            'registration_ids'=>$registration_ids,
                            'data'=>[
                                'url'=>$notification['permalink']
                                ]
                            ]
                    ];
                    array_push($sent,$fields);
                    $registration_ids=[];
                }
            }
            if(sizeof($registration_ids)>0){
                $fields = array(
                    'notification'=>$notification,
                    'body'=>[
                        "notification"=>$msg,
                        'registration_ids'=>$registration_ids,
                        'sound'=>'default',
                        'priority'=>'high',
                        'android' =>[
                            'notification'=>[
                                'sound'=>'notification'
                                ]
                            ],
                        'data'=>array(
                            'url'=>$notification['permalink']
                        )
                    ]
                );
                array_push($sent,$fields);            
            }
        }
        $headers =[
            'Authorization'=>'key='.$this->_FIREBASE_ID,
            'Content-Type'=>'application/json'
        ];
        $deleteUsers=[];
        if(count($sent)){
            foreach($sent as $field){
                $body=$field['body'];
                $response=wp_remote_post('https://fcm.googleapis.com/fcm/send',[
                    'method'=>'POST',
                    'httpversion' => '1.0',
                    'blocking'    => true,
                    'headers'=>$headers,
                    'body'=>json_encode( $body )
                ]);
                $result_body=json_decode($response['body']);
                $results=$result_body->results;
                $success=$result_body->success;
                for($i=0;$i<count($results);$i++){
                    if($results[$i]->error){
                        array_push($deleteUsers,"'".$body['registration_ids'][$i]."'");
                    }
                }
                $notification=$field['notification'];
                $this->wpdb->query("UPDATE `{$this->wpdb->prefix}notifications_post` SET notification_date=NOW(), notification_count='{$success}' WHERE id={$notification['id']}");
            }
        }
        if($deleteUsers && count($deleteUsers)>0)
            $this->wpdb->query("DELETE FROM  `{$this->wpdb->prefix}notifications_registered_users` WHERE firebase_id IN  (".join(",",$deleteUsers).")");
        
    }
    /**
     * Register plugin styles and scripts
     */
    public function register_wpn_scripts() {

        wp_register_script( 'wpn-script', plugins_url( 'js/wp-push-notifications.js', __FILE__ ), array('jquery'), null, true );
        wp_register_style( 'wpn-style', plugins_url( 'css/wp-push-notifications.css',__FILE__ ) );
        wp_localize_script( 'wpn-script', 'wp_push_notifications_ajax', array( 'ajax_url' => admin_url('admin-ajax.php')) );
    }   
    /**
     * Enqueues plugin-specific scripts.
     */
    public function enqueue_wpn_scripts() {        
        wp_enqueue_script( 'wpn-script' );
    }   
    /**
     * Enqueues plugin-specific styles.
     */
    public function enqueue_wpn_styles() {         
        wp_enqueue_style( 'wpn-style' ); 
    }
    //aqui debemos entonces lanzar el post
    public function on_new_post_published($ID,$post)
    {

    }
    private function init_db()
    {
        /*
        $sql="DROP TABLE IF EXISTS  `{$this->wpdb->prefix}notifications_post`";
        $this->wpdb->query($this->wpdb->prepare($sql));
        */
        $sql="CREATE TABLE IF NOT EXISTS `{$this->wpdb->prefix}notifications_post` ( `id` INT NOT NULL AUTO_INCREMENT , `id_post` INT NOT NULL , `id_user` INT NOT NULL , `permalink` VARCHAR(250) NOT NULL, `title` TEXT, `notification_count` INT NOT NULL , `creation_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP , `notification_date` DATETIME NULL DEFAULT NULL , PRIMARY KEY (`id`))";
        $this->wpdb->query($sql);
        // $sql="DROP TABLE IF EXISTS  `{$this->wpdb->prefix}notifications_registered_users`";
        // $this->wpdb->query($this->wpdb->prepare($sql));
        $sql="CREATE TABLE IF NOT EXISTS `{$this->wpdb->prefix}notifications_registered_users` ( `id` INT NOT NULL AUTO_INCREMENT , `firebase_id` VARCHAR(250) NOT NULL , `device_type` VARCHAR(32) NOT NULL , `notification_count` INT NOT NULL , `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP , `updated_at` DATETIME NULL DEFAULT NULL , PRIMARY KEY (`id`), UNIQUE `unique_fid` (`firebase_id`))";
        $this->wpdb->query($sql);
// RESET THE NOTIFICATIONS FOR DEBUG PORPOSES ONLY
//        $sql="DELETE FROM `{$this->wpdb->prefix}notifications_post`";
//        $this->wpdb->query($sql);

    }

    /**
 * Adds a notify now at the bottom of each post excerpt that allows logged in users to save those posts in their read me later list.
 *
 * @param string $content
 * @returns string
 */
    public function wpn_button( $content ) {   
    // Show read me later link only when the user is logged in    
        if( is_user_logged_in() && get_post_type() == post ) {
            //CHECKS
            $results = $this->wpdb->get_row(  "SELECT count(*) as TOTAL FROM {$this->wpdb->prefix}notifications_post WHERE id_post=".get_the_id(),  OBJECT );
            if($results->TOTAL==0){
                $html = '<a href="#" class="wpn_bttn" data-id="' . get_the_id() . '">Notifier</a>';
            }else{
                $html = '<div href="#" class="wpn_bttn notified">Notifié</div>';
                
            }
            $content .= $html;
        }
        return $content;       
    }

    public function register_device()
    {
        $firebase_id=$_POST['firebase_id'];
        $device_type=$_POST['device_type'];
        $results = $this->wpdb->get_row(  "SELECT count(*) as TOTAL FROM `{$this->wpdb->prefix}notifications_registered_users` WHERE firebase_id='".$firebase_id."'", OBJECT);
        if($results->TOTAL==0){
            $this->wpdb->insert("{$this->wpdb->prefix}notifications_registered_users",
                    array('firebase_id'=>$firebase_id,
                    'device_type'=>$device_type),
                    array('%s','%s'));
            $echo['insert_id']=$this->wpdb->insert_id;
        }else{
            $this->wpdb->update("{$this->wpdb->prefix}notifications_registered_users",
            array("updated_at"=>date("Y-m--d H:i:s")),
            array("firebase_id"=>$firebase_id));
            $echo['insert_id']="UPDATED";
        }
        print json_encode($echo);
        die();
    }
    public function notify_now() {
        $_post_id = $_POST['post_id']; 
        $echo = array();      
        $id=get_permalink( $_post_id  );
        $title=get_the_title( $_post_id  );        
        $echo=['id'=>$id, 'title'=>$title];
        $this->wpdb->insert("{$this->wpdb->prefix}notifications_post",
                array('id_post'=>$_post_id,
                'permalink'=>$id,
                'title'=>$title),
                array('%d','%s','%s'));
        $echo['insert_id']=$this->wpdb->insert_id;
        $echo['body']=file_get_contents('php://input');
        $echo['post']=$_POST;
        $echo['server']=$_SERVER;
        
        print json_encode($echo);
        die();
    }
 }

 new WpPostPushNotifications();