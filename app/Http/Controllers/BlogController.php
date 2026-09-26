<?php
namespace App\Http\Controllers;
use App\Models\BlogPost;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
class BlogController {
 public function published(Request $r) {
  $r->validate(['page'=>'nullable|integer|min:1']);
  return BlogPost::published()->orderByDesc('published_at')->orderByDesc('id')->paginate(12,['id','title','slug','excerpt','author_name','published_at','updated_at']);
 }
 public function show(string $slug) { return BlogPost::published()->where('slug',$slug)->firstOrFail(); }
 public function index(Request $r) { $r->validate(['page'=>'nullable|integer|min:1']); return BlogPost::latest('id')->paginate(20); }
 private function data(Request $r, ?BlogPost $post=null): array {
  $d=$r->validate(['title'=>'required|string|max:180','slug'=>['required','string','max:180','regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',Rule::unique('blog_posts','slug')->ignore($post?->id)],'excerpt'=>'required|string|max:320','body'=>'required|string|min:100|max:100000','author_name'=>'required|string|max:120','status'=>'required|in:draft,published']);
  $d['published_at']=$d['status']==='published'?($post?->published_at??now()):null;
  return $d;
 }
 public function store(Request $r) { $post=BlogPost::create($this->data($r)); AuthController::audit($r,'blog_created:'.$post->id,$r->user()->id); return response()->json($post,201); }
 public function update(Request $r,BlogPost $post) { $post->update($this->data($r,$post)); AuthController::audit($r,'blog_updated:'.$post->id,$r->user()->id); return $post; }
 public function destroy(Request $r,BlogPost $post) { $id=$post->id; $post->delete(); AuthController::audit($r,'blog_deleted:'.$id,$r->user()->id); return response()->noContent(); }
}
