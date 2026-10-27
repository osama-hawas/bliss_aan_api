# Laravel Clean API Boilerplate & CRUD Demo 🚀

هذا المشروع عبارة عن قالب هيكلي (Boilerplate / Starter Kit) يعتمد على بنية نظيفة وممتازة للتطبيقات الكبيرة وخصوصاً الـ APIs الموجهة للوحات التحكم وتطبيقات الموبايل.

يحتوي المشروع على **مثال CRUD كامل لمنتج (Demo Product)** يربط كل الطبقات ببعضها بطريقة مثالية لتسهيل الفهم والمحاكاة.

---

## 🛠️ المميزات الرئيسية (Core Features)

1. **الروات التلقائية (Dynamic Route Autoloading)**:
   * أي ملف روات `.php` تضعه داخل المجلد الفرعي `routes/api/v1/...` يتم تضمينه تلقائياً.
   * يتم بناء الـ **URL Prefix** للروابط تلقائياً بالاعتماد على أسماء المجلدات (Nested Folder Prefixing).
   
2. **الكنترولرات أحادية الإجراء (Invokable/Single Action Controllers)**:
   * كل كنترولر يحتوي على دالة واحدة فقط `__invoke` ومسؤول عن إجراء واحد (مثل: `StoreProductController`).
   * تزيد من تنظيم الكود وتسهل عملية الاختبار (Testing).

3. **الاستجابة الموحدة (Standardized ApiResponse Trait)**:
   * توحيد شكل الاستجابة (JSON Structure) لجميع الـ Endpoints باستخدام الـ Trait المساعد `ApiResponse`.

4. **فصل منطق الأعمال (Service Layer Pattern)**:
   * الكنترولر لا يحتوي على أكواد تعامل مباشرة مع قاعدة البيانات، بل يتم عزلها داخل فئات الـ `Services`.

5. **المصادقة باستخدام JWT (JSON Web Tokens)**:
   * الحزمة مجهزة مسبقاً وجاهزة لتأمين مساراتك باستخدام `jwt` Guard.

---

## 📂 الهيكل التنظيمي (Folder Structure)

```text
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── Api/V1/        
│   │   │       └── Demo/      <-- الكنترولرات أحادية الإجراء (Invokable)
│   │   │           ├── ListProductsController.php
│   │   │           ├── ShowProductController.php
│   │   │           ├── StoreProductController.php
│   │   │           ├── UpdateProductController.php
│   │   │           └── DeleteProductController.php
│   │   ├── Requests/          
│   │   │   └── Api/V1/Demo/   <-- طبقة التحقق من صحة البيانات (Validation)
│   │   │       ├── StoreProductRequest.php
│   │   │       └── UpdateProductRequest.php
│   │   └── Resources/         
│   │       └── Api/V1/Demo/   <-- طبقة تنسيق وتغليف المخرجات (Response Format)
│   │           └── ProductResource.php
│   ├── Models/                
│   │   └── DemoProduct.php    <-- نموذج قاعدة البيانات (Eloquent Model)
│   ├── Services/              
│   │   └── Demo/              <-- طبقة معالجة منطق الأعمال (Business Logic)
│   │       └── ProductService.php
│   └── Traits/
│       └── ApiResponse.php    <-- مساعد الاستجابة الموحدة للـ APIs
├── routes/
│   └── api/
│       └── v1/                
│           └── demo/          <-- ملفات الروابط مقسمة حسب المجلدات
│               └── products.php
└── README.md
```

---

## 🚀 كيفية استخدام الاستراكشر (How to Use)

### 1. الروات (Routes)
عند إنشاء ملف الروابط مثل `routes/api/v1/demo/products.php` بالمسارات التالية:
```php
Route::post('/products', StoreProductController::class);
```
سيتم توليد الرابط تلقائياً بناءً على المجلد الفرعي ليكون:
`POST http://localhost:8000/api/v1/demo/products`

### 2. الكنترولر (Controller)
الكنترولر يستدعي الـ Service والـ Request والـ Resource ويعيد الاستجابة كالتالي:
```php
class StoreProductController extends Controller
{
    use ApiResponse;

    protected $productService;

    public function __construct(ProductService $productService)
    {
        $this->productService = $productService;
    }

    public function __invoke(StoreProductRequest $request)
    {
        $product = $this->productService->store($request->validated());

        return $this->success(
            new ProductResource($product),
            'Product created successfully.',
            201
        );
    }
}
```

---

## 🧪 الروابط الجاهزة للتجربة (Postman Endpoint Examples)

* **جلب جميع المنتجات (`GET`)**:
  `http://127.0.0.1:8000/api/v1/demo/products`
* **جلب تفاصيل منتج (`GET`)**:
  `http://127.0.0.1:8000/api/v1/demo/products/1`
* **إنشاء منتج (`POST`)**:
  `http://127.0.0.1:8000/api/v1/demo/products`
  *(إرسال بيانات `name`, `price`, `description` في الـ Body)*
* **تعديل منتج (`PUT`)**:
  `http://127.0.0.1:8000/api/v1/demo/products/1`
* **حذف منتج (`DELETE`)**:
  `http://127.0.0.1:8000/api/v1/demo/products/1`
