@inline(never)
func nativeWatchFixtureFailure(_ value: Int) -> Int {
    precondition(value != 0, "NativeWatch symbolication fixture")
    return 84 / value
}

print(nativeWatchFixtureFailure(CommandLine.arguments.count - 1))
