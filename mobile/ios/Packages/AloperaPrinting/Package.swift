// swift-tools-version:5.9
//
// جسرُ الطباعة المباشرة في تطبيق Alopera Gym — بلا UIKit ولا AppKit، فيُبنى ويُختبر
// على macOS كما يعمل على iOS. والعقدُ في mobile/PROTOCOL.md.
import PackageDescription

let package = Package(
    name: "AloperaPrinting",
    platforms: [.iOS(.v15), .macOS(.v13)],
    products: [
        .library(name: "AloperaPrinting", targets: ["AloperaPrinting"]),
    ],
    targets: [
        .target(name: "AloperaPrinting"),
        .testTarget(name: "AloperaPrintingTests", dependencies: ["AloperaPrinting"]),
    ]
)
