#!/usr/bin/env python3
"""Regenerate the dependency-free Xcode project using only Python's standard library."""
from pathlib import Path
import hashlib
root = Path(__file__).resolve().parent
project = root / 'TotalLog.xcodeproj'
def uid(s): return hashlib.sha1(s.encode()).hexdigest()[:24].upper()
objects = {}
def obj(key, body): objects[uid(key)] = body; return uid(key)
files=[]; builds=[]
for path in sorted((root/'TotalLog').glob('*.swift')):
    ref=obj(str(path.name), f'isa = PBXFileReference; lastKnownFileType = sourcecode.swift; path = {path.name}; sourceTree = "<group>";')
    files.append(ref)
    builds.append(obj('build-'+path.name, f'isa = PBXBuildFile; fileRef = {ref};'))
asset=obj('assets','isa = PBXFileReference; lastKnownFileType = folder.assetcatalog; path = Assets.xcassets; sourceTree = "<group>";')
assetbuild=obj('assetsbuild',f'isa = PBXBuildFile; fileRef = {asset};')
privacy=obj('privacy', 'isa = PBXFileReference; lastKnownFileType = text.xml; path = PrivacyInfo.xcprivacy; sourceTree = "<group>";')
privacybuild=obj('privacybuild', f'isa = PBXBuildFile; fileRef = {privacy};')
app=obj('product','isa = PBXFileReference; explicitFileType = wrapper.application; path = TotalLog.app; sourceTree = BUILT_PRODUCTS_DIR;')
group=obj('sources',f'isa = PBXGroup; children = ({",".join(files+[asset,privacy])},); path = TotalLog; sourceTree = "<group>";')
products=obj('products',f'isa = PBXGroup; children = ({app},); name = Products; sourceTree = "<group>";')
main=obj('main',f'isa = PBXGroup; children = ({group},{products},); sourceTree = "<group>";')
sources=obj('sourcephase',f'isa = PBXSourcesBuildPhase; buildActionMask = 2147483647; files = ({",".join(builds)},); runOnlyForDeploymentPostprocessing = 0;')
resources=obj('resourcephase',f'isa = PBXResourcesBuildPhase; buildActionMask = 2147483647; files = ({assetbuild},{privacybuild},); runOnlyForDeploymentPostprocessing = 0;')
frameworks=obj('frameworkphase','isa = PBXFrameworksBuildPhase; buildActionMask = 2147483647; files = (); runOnlyForDeploymentPostprocessing = 0;')
configs=[]; pconfigs=[]
for name in ['Debug','Release']:
    settings='CODE_SIGN_STYLE = Automatic; CURRENT_PROJECT_VERSION = 1; GENERATE_INFOPLIST_FILE = YES; INFOPLIST_FILE = TotalLog/Info.plist; INFOPLIST_KEY_CFBundleDisplayName = TotalLog; INFOPLIST_KEY_LSApplicationCategoryType = "public.app-category.productivity"; INFOPLIST_KEY_UILaunchScreen_Generation = YES; INFOPLIST_KEY_UIApplicationSceneManifest_Generation = YES; INFOPLIST_KEY_UISupportedInterfaceOrientations = "UIInterfaceOrientationPortrait UIInterfaceOrientationLandscapeLeft UIInterfaceOrientationLandscapeRight"; IPHONEOS_DEPLOYMENT_TARGET = 17.0; MARKETING_VERSION = 0.1.0; PRODUCT_BUNDLE_IDENTIFIER = com.totallog.iphone; PRODUCT_NAME = "$(TARGET_NAME)"; SDKROOT = iphoneos; SUPPORTED_PLATFORMS = "iphoneos iphonesimulator"; SWIFT_VERSION = 5.0; TARGETED_DEVICE_FAMILY = 1; ASSETCATALOG_COMPILER_APPICON_NAME = AppIcon;'
    settings += ' SWIFT_OPTIMIZATION_LEVEL = "-Onone"; DEBUG_INFORMATION_FORMAT = dwarf; SWIFT_ACTIVE_COMPILATION_CONDITIONS = DEBUG;' if name=='Debug' else ' SWIFT_COMPILATION_MODE = wholemodule; DEBUG_INFORMATION_FORMAT = "dwarf-with-dsym";'
    configs.append(obj('target'+name,f'isa = XCBuildConfiguration; buildSettings = {{{settings}}}; name = {name};'))
    pconfigs.append(obj('project'+name,f'isa = XCBuildConfiguration; buildSettings = {{CLANG_ENABLE_MODULES = YES;}}; name = {name};'))
cl=obj('configs',f'isa = XCConfigurationList; buildConfigurations = ({",".join(configs)},); defaultConfigurationIsVisible = 0; defaultConfigurationName = Release;')
pcl=obj('pconfigs',f'isa = XCConfigurationList; buildConfigurations = ({",".join(pconfigs)},); defaultConfigurationIsVisible = 0; defaultConfigurationName = Release;')
target=obj('target',f'isa = PBXNativeTarget; buildConfigurationList = {cl}; buildPhases = ({sources},{frameworks},{resources},); buildRules = (); dependencies = (); name = TotalLog; productName = TotalLog; productReference = {app}; productType = "com.apple.product-type.application";')
proj=obj('project',f'isa = PBXProject; attributes = {{BuildIndependentTargetsInParallel = YES; LastUpgradeCheck = 2600; TargetAttributes = {{{target} = {{CreatedOnToolsVersion = 26.0;}};}};}}; buildConfigurationList = {pcl}; compatibilityVersion = "Xcode 14.0"; developmentRegion = en; hasScannedForEncodings = 0; knownRegions = (en,Base,); mainGroup = {main}; productRefGroup = {products}; projectDirPath = ""; projectRoot = ""; targets = ({target},);')
(project/'project.pbxproj').write_text('// !$*UTF8*$!\n{ archiveVersion = 1; classes = {}; objectVersion = 56; objects = {\n'+ '\n'.join(k+' = {'+v+'};' for k,v in objects.items())+'\n}; rootObject = '+proj+'; }\n')
scheme=f'''<?xml version="1.0" encoding="UTF-8"?>
<Scheme LastUpgradeVersion="2600" version="1.3"><BuildAction parallelizeBuildables="YES" buildImplicitDependencies="YES"><BuildActionEntries><BuildActionEntry buildForTesting="YES" buildForRunning="YES" buildForProfiling="YES" buildForArchiving="YES" buildForAnalyzing="YES"><BuildableReference BuildableIdentifier="primary" BlueprintIdentifier="{target}" BuildableName="TotalLog.app" BlueprintName="TotalLog" ReferencedContainer="container:TotalLog.xcodeproj"/></BuildActionEntry></BuildActionEntries></BuildAction><LaunchAction buildConfiguration="Debug" selectedDebuggerIdentifier="Xcode.DebuggerFoundation.Debugger.LLDB" selectedLauncherIdentifier="Xcode.IDEFoundation.Launcher.LLDB" launchStyle="0" useCustomWorkingDirectory="NO" ignoresPersistentStateOnLaunch="NO" debugDocumentVersioning="YES" allowLocationSimulation="YES"><BuildableProductRunnable runnableDebuggingMode="0"><BuildableReference BuildableIdentifier="primary" BlueprintIdentifier="{target}" BuildableName="TotalLog.app" BlueprintName="TotalLog" ReferencedContainer="container:TotalLog.xcodeproj"/></BuildableProductRunnable></LaunchAction><ProfileAction buildConfiguration="Release" shouldUseLaunchSchemeArgsEnv="YES" savedToolIdentifier="" useCustomWorkingDirectory="NO" debugDocumentVersioning="YES"><BuildableProductRunnable runnableDebuggingMode="0"><BuildableReference BuildableIdentifier="primary" BlueprintIdentifier="{target}" BuildableName="TotalLog.app" BlueprintName="TotalLog" ReferencedContainer="container:TotalLog.xcodeproj"/></BuildableProductRunnable></ProfileAction><AnalyzeAction buildConfiguration="Debug"/><ArchiveAction buildConfiguration="Release" revealArchiveInOrganizer="YES"/></Scheme>'''
(project/'xcshareddata/xcschemes/TotalLog.xcscheme').write_text(scheme)
