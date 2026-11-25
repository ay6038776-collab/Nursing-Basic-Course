<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/instructor_guard.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') nbc_json(405,['success'=>false,'message'=>'طريقة الطلب غير مدعومة.']);
$purpose=(string)($_POST['purpose']??'image');
$file=$_FILES['file']??null;
if(!$file||!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)nbc_json(422,['success'=>false,'message'=>'اختر ملفًا صالحًا للرفع.']);
$maxBytes=$purpose==='source'?25*1024*1024:8*1024*1024;
if((int)$file['size']<1||(int)$file['size']>$maxBytes)nbc_json(413,['success'=>false,'message'=>'حجم الملف أكبر من الحد المسموح.']);
$finfo=new finfo(FILEINFO_MIME_TYPE);$mime=$finfo->file((string)$file['tmp_name']);
$allowed=$purpose==='source'?['application/pdf'=>'pdf','application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx','text/plain'=>'txt']:['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
if(!isset($allowed[$mime]))nbc_json(415,['success'=>false,'message'=>$purpose==='source'?'المسموح PDF أو DOCX أو TXT فقط.':'المسموح صور JPG أو PNG أو WEBP فقط.']);
if($purpose!=='source'&&!getimagesize((string)$file['tmp_name']))nbc_json(415,['success'=>false,'message'=>'محتوى الصورة غير صالح.']);
$folder=$purpose==='source'?'assessment-sources':'questions';$directory=__DIR__.'/../../uploads/'.$folder;
if(!is_dir($directory)&&!mkdir($directory,0750,true)&&!is_dir($directory))nbc_json(500,['success'=>false,'message'=>'تعذر تجهيز مجلد الرفع.']);
$name='q_'.$currentInstructor['id'].'_'.bin2hex(random_bytes(16)).'.'.$allowed[$mime];
if(!move_uploaded_file((string)$file['tmp_name'],$directory.'/'.$name))nbc_json(500,['success'=>false,'message'=>'تعذر حفظ الملف.']);
if($purpose==='source'){
  $privateRule=$directory.'/.htaccess';
  if(!is_file($privateRule))file_put_contents($privateRule,"Require all denied\nOptions -Indexes\n");
  $path='uploads/'.$folder.'/'.$name;
}else{$path='uploads/'.$folder.'/'.$name;}
nbc_json(201,['success'=>true,'path'=>$path,'name'=>(string)($file['name']??$name),'mime'=>$mime]);
