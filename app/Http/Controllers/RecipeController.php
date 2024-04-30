<?php

namespace App\Http\Controllers;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use App\Http\Requests\RecipeCreateRequest;
use App\Models\Category;
use App\Models\Ingredient;
use Illuminate\Http\Request;
use App\Models\Recipe;
use App\Models\Step;

class RecipeController extends Controller
{
    /**
     * Display a listing of the resource.
     */

    public function home(){

        $recipes = Recipe::select('recipes.id', 'recipes.title', 'recipes.description', 'recipes.created_at', 'recipes.image', 'users.name')
            ->join('users', 'users.id', '=', 'recipes.user_id')
            ->orderBy('recipes.created_at', 'desc')
            ->limit(3)
            ->get();
        // dd($recipes);

        $popular = Recipe::select('recipes.id', 'recipes.title', 'recipes.description', 'recipes.created_at', 'recipes.image', 'recipes.views', 'users.name')
            ->join('users', 'users.id', '=', 'recipes.user_id')
            ->orderBy('recipes.views', 'desc')
            ->limit(2)
            ->get();
        //dd($popular);

        return view('home', compact('recipes', 'popular'));
    }
    
    public function index(Request $request)
    {
        $filters = $request->all();
        //dd($filters);
        $query = Recipe::query()->select('recipes.id', 'recipes.title', 'recipes.description', 'recipes.created_at', 'recipes.image', 'users.name', \DB::raw('AVG(reviews.rating) as rating'))
            ->join('users', 'users.id', '=', 'recipes.user_id')
            ->leftjoin('reviews', 'reviews.recipe_id', '=', 'recipes.id')
            ->groupBy('recipes.id')
            ->orderBy('recipes.created_at', 'desc');
        
        if(!empty($filters)){
            // カテゴリが選択された場合
            if(!empty($filters['categories'])){
                $query->whereIn('recipes.category_id', $filters['categories']);
            }
            // 評価が選択された場合
            if(!empty($filters['rating'])){
                $query->havingRaw('AVG(reviews.rating) >= ?', [$filters['rating']]);
            }// レシピ名の曖昧検索の場合
            if(!empty($filters['title'])){
                $query->where('recipes.title', 'like', '%'.$filters['title'].'%');
            }
        }
        $recipes = $query->paginate(5);
        //dd($recipes);

        $categories = Category::all();

        return view('recipes.index', compact('recipes', 'categories', 'filters'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $categories = Category::all();
        return view('recipes.create', compact('categories'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(RecipeCreateRequest $request)
    {
        $posts = $request->all();
        $uuid = Str::uuid()->toString();
        //dd($posts);

        $image = $request->file('image');
        $path = Storage::disk('s3')->putFile('recipe', $image, 'public'); // s3にアップロード
        $url = Storage::disk('s3')->url($path); // s3のURLを取得

        try {
            DB::beginTransaction();
            Recipe::insert([
                'id' => $uuid,
                'title'=> $posts['title'],
                'description' => $posts['description'],
                'category_id' => $posts['category'],
                'image' => $url, // DBにs3のURLを保存
                'user_id' => Auth::id(),
            ]);

            $ingredients = [];
            foreach($posts['ingredients'] as $key => $ingredient){
                $ingredients[$key] = [
                    'recipe_id' => $uuid,
                    'name' => $ingredient['name'],
                    'quantity' => $ingredient['quantity'],
                ];
            }
            Ingredient::insert($ingredients);

            $steps = [];
            foreach($posts['steps'] as $key => $step){
                $steps[$key] = [
                    'recipe_id' => $uuid,
                    'step_number' => $key + 1,
                    'description' => $step
                ];
            }
            STEP::insert($steps);

            DB::commit();
        } catch(\Throwable $th){
            DB::rollBack();
            \log::debug(print_r($th->getMessage(), true)); // 失敗した時、エラーメッセージをログファイルに書き出す。
            throw $th;
        }
        flash()->success('レシピを投稿しました！');
        return redirect()->route('recipe.show', ['id' => $uuid]);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id) //show(Recipe $recipe) メソッドインジェクション
    {
        $recipe = Recipe::with(['ingredients', 'steps', 'reviews.user', 'user'])
            ->where('recipes.id', $id)
            ->first();
        $recipe_recorde = Recipe::find($id);
        $recipe_recorde->increment('views');
        
        // レシピの投稿者とログインユーザが同じかどうか判定する。
        $is_my_recipe = false;
        if(Auth::check() && (Auth::id() === $recipe['user_id'])){
            $is_my_recipe = true;
        }
        
        return view('recipes.show', compact('recipe', 'is_my_recipe'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $recipe = Recipe::with(['ingredients', 'steps', 'reviews.user', 'user'])
            ->where('recipes.id', $id)
            ->first()->toArray();
        $categories = Category::all();

        return view('recipes.edit', compact('recipe', 'categories'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $posts = $request->all();
        try{
            DB::beginTransaction();
            Recipe::where('id', $id)->update([
                'title' => $posts['title'],
                'description' => $posts['description'],
                'category_id' => $posts['category_id'],
            ]);
            // すでに登録されている材料と手順を削除してから、新しいものを登録する。
            Ingredient::where('recipe_id', $id)->delete();
            Step::where('recipe_id', $id)->delete();
            
            $ingredients = [];
            foreach($posts['ingredients'] as $key => $ingredient){
                $ingredients[$key] = [
                    'recipe_id' => $id,
                    'name' => $ingredient['name'],
                    'quantity' => $ingredient['quantity'],
                ];
            }
            Ingredient::insert($ingredients);

            $steps = [];
            foreach($posts['steps'] as $key => $step){
                $steps[$key] = [
                    'recipe_id' => $id,
                    'step_number' => $key + 1,
                    'description' => $step
                ];
            }
            STEP::insert($steps);
            DB::commit();
            } catch(\Throwable $th){
                DB::rollBack();
                \log::debug(print_r($th->getMessage(), true));
                throw $th;
            }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
