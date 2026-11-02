import java.util.Properties

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

// عنوانُ Alopera Gym وأصولُه الموثوقة لا تُكتب في الكود: خاصيّةُ Gradle أوّلًا
// (-Palopera.baseUrl=…)، ثمّ mobile/android/local.properties، وإلّا فارغة.
// والبناءُ ينجح فارغًا — والتطبيقُ حينها يعرض شاشةَ «لم يُضبط».
val localAloperaProperties = Properties().apply {
    val file = rootProject.file("local.properties")
    if (file.isFile) {
        file.inputStream().use { load(it) }
    }
}

val aloperaBaseUrl: String = (
    providers.gradleProperty("alopera.baseUrl").orNull
        ?: localAloperaProperties.getProperty("alopera.baseUrl")
        ?: ""
    ).trim()

val aloperaTrustedOrigins: String = (
    providers.gradleProperty("alopera.trustedOrigins").orNull
        ?: localAloperaProperties.getProperty("alopera.trustedOrigins")
        ?: ""
    ).trim()

// توقيعُ الإصدار — من خارج المستودع وحده: `~/.gradle/gradle.properties` أو `-P…`
// أو local.properties (غيرُ مودَع). ولا مفتاحَ ولا كلمةَ سرٍّ في git. وبلا
// `alopera.signing.storeFile` يُبنى الإصدارُ بلا توقيع، والتجربةُ بـassembleDebug.
fun aloperaProperty(name: String): String? =
    (providers.gradleProperty(name).orNull ?: localAloperaProperties.getProperty(name))
        ?.trim()
        ?.takeIf { it.isNotEmpty() }

val releaseStoreFile: String? = aloperaProperty("alopera.signing.storeFile")

fun javaStringLiteral(value: String): String =
    "\"" + value
        .replace("\\", "\\\\")
        .replace("\"", "\\\"")
        .replace("\n", "")
        .replace("\r", "") + "\""

android {
    namespace = "om.alopera.gym"
    compileSdk = 35

    defaultConfig {
        applicationId = "om.alopera.gym"
        minSdk = 26
        targetSdk = 35
        versionCode = 1
        versionName = "1.0.0"

        buildConfigField("String", "ALOPERA_BASE_URL", javaStringLiteral(aloperaBaseUrl))
        buildConfigField("String", "ALOPERA_TRUSTED_ORIGINS", javaStringLiteral(aloperaTrustedOrigins))
    }

    signingConfigs {
        if (releaseStoreFile != null) {
            create("release") {
                storeFile = file(releaseStoreFile)
                storePassword = aloperaProperty("alopera.signing.storePassword")
                keyAlias = aloperaProperty("alopera.signing.keyAlias")
                keyPassword = aloperaProperty("alopera.signing.keyPassword")
            }
        }
    }

    buildTypes {
        getByName("release") {
            isMinifyEnabled = false
            signingConfig = signingConfigs.findByName("release")
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = "17"
    }

    buildFeatures {
        buildConfig = true
    }

    sourceSets {
        getByName("main") {
            // الـshim المشترك يُشحن كما هو: assets/alopera-printer-shim.js
            assets.srcDir("../../shared/js")
        }
    }
}

dependencies {
    implementation("androidx.core:core-ktx:1.15.0")
    implementation("androidx.appcompat:appcompat:1.7.0")
    implementation("androidx.webkit:webkit:1.12.1")

    testImplementation("junit:junit:4.13.2")
    // org.json في android.jar مجرّدُ هياكل في اختبارات JVM
    testImplementation("org.json:json:20240303")
}
